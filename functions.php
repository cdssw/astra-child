<?php
if (!defined('ABSPATH')) exit;

/**
 * Astra Child functions.php (최종본)
 * - 캠핑장 CPT/택소노미
 * - 아카이브 필터(region, camp_theme)
 * - 스타일 로드(루트 상대경로)
 * - 캠핑장 상세에서만 카카오맵 로드 + 좌표 기반 초기화
 * - (선택) JSON-LD 최소 정보
 * - 기타: 제목 숨김 등
 */

/* 테마 전환 시 리라이트 갱신 */
add_action('after_switch_theme', function () {
  flush_rewrite_rules();
});

/* 캠핑장 CPT + 지역/테마 택소노미 */
add_action('init', function () {
  register_post_type('campground', array(
    'labels' => array(
      'name' => '캠핑장',
      'singular_name' => '캠핑장',
      'add_new' => '새 캠핑장 추가',
      'add_new_item' => '캠핑장 추가',
      'edit_item' => '캠핑장 편집',
      'new_item' => '새 캠핑장',
      'view_item' => '캠핑장 보기',
      'search_items' => '캠핑장 검색',
      'not_found' => '캠핑장을 찾을 수 없음',
      'not_found_in_trash' => '휴지통에 캠핑장이 없음',
      'all_items' => '모든 캠핑장',
    ),
    'public' => true,
    'has_archive' => true,
    'rewrite' => array('slug' => 'camping', 'with_front' => false),
    'menu_icon' => 'dashicons-location-alt',
    'supports' => array('title', 'editor', 'excerpt', 'custom-fields'), // thumbnail 제외
    'show_in_rest' => true,
  ));

  register_taxonomy('region', 'campground', array(
    'labels' => array(
      'name' => '지역',
      'singular_name' => '지역',
      'search_items' => '지역 검색',
      'all_items' => '모든 지역',
      'edit_item' => '지역 편집',
      'update_item' => '지역 업데이트',
      'add_new_item' => '지역 추가',
      'new_item_name' => '새 지역',
    ),
    'hierarchical' => true,
    'public' => true,
    'rewrite' => array('slug' => 'region', 'with_front' => false),
    'show_in_rest' => true,
  ));

  register_taxonomy('camp_theme', 'campground', array(
    'labels' => array(
      'name' => '캠핑 테마',
      'singular_name' => '캠핑 테마',
      'search_items' => '테마 검색',
      'all_items' => '모든 테마',
      'edit_item' => '테마 편집',
      'update_item' => '테마 업데이트',
      'add_new_item' => '테마 추가',
      'new_item_name' => '새 테마',
    ),
    'hierarchical' => false,
    'public' => true,
    'rewrite' => array('slug' => 'camp-theme', 'with_front' => false),
    'show_in_rest' => true,
  ));
});

/* 아카이브 필터: /camping/?region=slug&camp_theme=slug */
add_action('pre_get_posts', function ($q) {
  if (is_admin() || !$q->is_main_query()) return;
  if (!$q->is_post_type_archive('campground')) return;

  $region     = (string)(filter_input(INPUT_GET, 'region', FILTER_SANITIZE_FULL_SPECIAL_CHARS) ?: '');
  $camp_theme = (string)(filter_input(INPUT_GET, 'camp_theme', FILTER_SANITIZE_FULL_SPECIAL_CHARS) ?: '');

  $tax_query = array('relation' => 'AND');
  if ($region !== '') {
    $tax_query[] = array('taxonomy' => 'region', 'field' => 'slug', 'terms' => $region);
  }
  if ($camp_theme !== '') {
    $tax_query[] = array('taxonomy' => 'camp_theme', 'field' => 'slug', 'terms' => $camp_theme);
  }
  if (count($tax_query) > 1) $q->set('tax_query', $tax_query);

  $q->set('posts_per_page', 12);
});

// 검색 페이지 전용: 캠핑장도 검색 대상에 포함
add_action('pre_get_posts', 'cg_search_query', 10);
function cg_search_query($q)
{
  if (is_admin() || !$q->is_main_query() || !$q->is_search()) return;
  $q->set('post_type', array('campground', 'post', 'page'));
  $q->set('posts_per_page', 10);
}

// 아스트라 기본 제목 숨김: 홈/검색/캠핑장 단일
add_filter('astra_the_title_enabled', function ($enabled) {
  if (is_front_page() || is_search() || is_singular('campground')) return false;
  return $enabled;
});

/* 스타일 로드: 루트 상대경로 + filemtime 버전 */
add_action('wp_enqueue_scripts', function () {
  $deps = array();
  if (wp_style_is('astra-theme-css', 'enqueued') || wp_style_is('astra-theme-css', 'registered')) {
    $deps[] = 'astra-theme-css';
  }
  $child_path = WP_CONTENT_DIR . '/themes/astra-child/style.css';
  $child_uri  = '/wp-content/themes/astra-child/style.css';
  if (file_exists($child_path)) {
    wp_enqueue_style('astra-child-style', $child_uri, $deps, filemtime($child_path));
  }
  $custom_path = WP_CONTENT_DIR . '/themes/astra-child/assets/css/custom.css';
  $custom_uri  = '/wp-content/themes/astra-child/assets/css/custom.css';
  if (file_exists($custom_path)) {
    wp_enqueue_style('astra-child-custom', $custom_uri, array('astra-child-style'), filemtime($custom_path));
  }
}, 50);

/* 카카오맵: 캠핑장 상세에서만 로드 + 좌표 기반 초기화 */
add_action('wp_enqueue_scripts', function () {
  if (!is_singular('campground')) return;

  // 1) JavaScript 키 사용(REST/네이티브 키 금지)
  $kakao_js_key = '06a3072f2b78afa7102c4e3653580ae2';
  wp_enqueue_script(
    'kakao-maps',
    'https://dapi.kakao.com/v2/maps/sdk.js?appkey=' . $kakao_js_key . '&autoload=false',
    array(),
    null,
    true
  );

  // 2) 메타 → JS로 전달(본문 data-*가 있으면 JS에서 우선 사용하도록 보조)
  $id   = get_queried_object_id();
  $lat  = get_post_meta($id, 'lat', true);
  $lng  = get_post_meta($id, 'lng', true);
  $addr = get_post_meta($id, 'address', true);

  wp_register_script('cg-map-init', false, array('kakao-maps'), null, true);
  wp_enqueue_script('cg-map-init');

  wp_localize_script('cg-map-init', 'cgMapData', array(
    'lat'     => $lat ? floatval($lat) : null,
    'lng'     => $lng ? floatval($lng) : null,
    'address' => $addr ?: '',
    'level'   => 4,
    'height'  => 280
  ));

  // 3) 초기화 스크립트(뷰포트 진입 시 1회 실행)
  wp_add_inline_script('cg-map-init', <<<JS
  (function(){
    function createMap(el, lat, lng, address, level){
      kakao.maps.load(function(){
        var pos = new kakao.maps.LatLng(lat, lng);
        var map = new kakao.maps.Map(el, { center: pos, level: level || 4 });
        new kakao.maps.Marker({ position: pos, map: map });

        var btn = el.parentElement && el.parentElement.querySelector('.cg-map-actions .open-map');
        if(btn){
          var url = address
            ? ('https://map.kakao.com/link/search/' + encodeURIComponent(address))
            : ('https://map.kakao.com/link/map/' + lat + ',' + lng);
          btn.addEventListener('click', function(e){ e.preventDefault(); window.open(url, '_self'); });
        }
      });
    }

    function initOne(el){
      // 1) 본문 data-* 우선
      var lat = parseFloat(el.dataset.lat), lng = parseFloat(el.dataset.lng);
      var addr = el.dataset.address || '';
      // 2) 없으면 서버 주입 값 사용
      if(isNaN(lat) || isNaN(lng)){
        lat = (window.cgMapData && window.cgMapData.lat) ? parseFloat(cgMapData.lat) : NaN;
        lng = (window.cgMapData && window.cgMapData.lng) ? parseFloat(cgMapData.lng) : NaN;
        addr = addr || (window.cgMapData ? (cgMapData.address || '') : '');
      }
      if(isNaN(lat) || isNaN(lng)) return;

      if(!el.style.height){ el.style.height = ((window.cgMapData && cgMapData.height) || 280) + 'px'; }
      createMap(el, lat, lng, addr, (window.cgMapData && cgMapData.level) || 4);
    }

    document.addEventListener('DOMContentLoaded', function(){
      var els = document.querySelectorAll('#cg-map, .cg-map');
      if(!els.length) return;

      if('IntersectionObserver' in window){
        var io = new IntersectionObserver(function(entries){
          entries.forEach(function(ent){
            if(ent.isIntersecting){
              initOne(ent.target);
              io.unobserve(ent.target);
            }
          });
        }, { rootMargin: '200px 0px' });

        els.forEach(function(el){ io.observe(el); });
      }else{
        els.forEach(initOne);
      }
    });
  })();
  JS);
}, 60);

add_filter('astra_the_title_enabled', function ($enabled) {
  if (is_front_page()) return false;
  return $enabled;
});

/* JSON-LD(최소) – 원치 않으면 주석 처리 */
add_action('wp_head', function () {
  if (!is_singular('campground')) return;
  $id = get_the_ID();
  $data = array(
    '@context' => 'https://schema.org',
    '@type'    => 'Campground',
    'name'     => get_the_title($id),
    'url'      => get_permalink($id),
  );
  $addr = get_post_meta($id, 'address', true);
  $tel  = get_post_meta($id, 'phone', true);
  $lat  = get_post_meta($id, 'lat', true);
  $lng  = get_post_meta($id, 'lng', true);
  if ($addr) $data['address'] = $addr;
  if ($tel)  $data['telephone'] = $tel;
  if ($lat && $lng) {
    $data['geo'] = array('@type' => 'GeoCoordinates', 'latitude' => floatval($lat), 'longitude' => floatval($lng));
  }
  echo '<script type="application/ld+json">' . wp_json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '</script>';
}, 30);

/* 필요 시: 메뉴 폴백 차단(메뉴 미지정 시 자동 페이지 목록 방지) */
// add_filter('wp_nav_menu_args', function ($args) { $args['fallback_cb'] = '__return_false'; return $args; });

/* 푸터에서 쓸 연도/사이트명 숏코드 */
add_shortcode('cg_year', function () {
  return esc_html(date_i18n('Y'));
});
add_shortcode('cg_sitename', function () {
  return esc_html(get_bloginfo('name'));
});

/* 빠른 필터(홈) — [cg_quick_filter] */
add_shortcode('cg_quick_filter', function () {
  $base = get_post_type_archive_link('campground');

  // 시도(부모) + 시군구 맵
  $provinces = get_terms(['taxonomy' => 'region', 'hide_empty' => false, 'parent' => 0, 'orderby' => 'name']);
  $region_map = [];
  if (!is_wp_error($provinces)) {
    foreach ($provinces as $p) {
      $children = get_terms(['taxonomy' => 'region', 'hide_empty' => false, 'parent' => $p->term_id, 'orderby' => 'name']);
      $region_map[$p->slug] = [];
      if (!is_wp_error($children)) {
        foreach ($children as $c) $region_map[$p->slug][] = ['slug' => $c->slug, 'name' => $c->name];
      }
    }
  }
  // 테마
  $themes = get_terms(['taxonomy' => 'camp_theme', 'hide_empty' => false, 'orderby' => 'name']);

  ob_start(); ?>
  <section class="home-quick" aria-label="빠른 필터">
    <form class="quick-form" id="home-quick-form" method="get" action="<?php echo esc_url($base); ?>">
      <!-- 시도 -->
      <label for="hq-province" class="screen-reader-text">시도 선택</label>
      <div class="sel">
        <select id="hq-province" name="region_do" aria-label="시도">
          <option value="">시도 전체</option>
          <?php if (!is_wp_error($provinces)) foreach ($provinces as $p): ?>
            <option value="<?php echo esc_attr($p->slug); ?>"><?php echo esc_html($p->name); ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <!-- 시군구 -->
      <label for="hq-city" class="screen-reader-text">시군구 선택</label>
      <div class="sel">
        <select id="hq-city" name="region_si" aria-label="시군구">
          <option value="">시군구 전체</option>
        </select>
      </div>

      <!-- 테마 -->
      <label for="hq-theme" class="screen-reader-text">테마 선택</label>
      <div class="sel">
        <select id="hq-theme" name="camp_theme" aria-label="테마">
          <option value="">테마 전체</option>
          <?php if (!is_wp_error($themes)) foreach ($themes as $t): ?>
            <option value="<?php echo esc_attr($t->slug); ?>"><?php echo esc_html($t->name); ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <!-- 서버 검색용 최종 region(시군구 우선, 없으면 시도) -->
      <input type="hidden" name="region" id="hq-region-final" value="">

      <div class="filter-actions">
        <button type="submit" class="ast-button btn-primary">필터 적용</button>
        <!-- 초기화는 홈에선 노출하지 않음 -->
      </div>

      <script>
        (function() {
          var MAP = <?php echo wp_json_encode($region_map, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
          var $do = document.getElementById('hq-province');
          var $si = document.getElementById('hq-city');
          var $fin = document.getElementById('hq-region-final');

          function fillCities(provSlug) {
            var def = document.createElement('option');
            def.value = '';
            def.textContent = '시군구 전체';
            var frag = document.createDocumentFragment();
            frag.appendChild(def);
            (MAP[provSlug] || []).forEach(function(c) {
              var o = document.createElement('option');
              o.value = c.slug;
              o.textContent = c.name;
              frag.appendChild(o);
            });
            while ($si.firstChild) $si.removeChild($si.firstChild);
            $si.appendChild(frag);
          }
          $do.addEventListener('change', function() {
            var p = (this.value || '').trim();
            fillCities(p);
            $fin.value = p || '';
          });
          $si.addEventListener('change', function() {
            var c = (this.value || '').trim();
            $fin.value = c ? c : ($do.value || '');
          });

          // placeholder 회색 표시(:has 미지원 보조)
          function markPlaceholder(sel) {
            if (!sel) return;
            sel.classList.toggle('is-placeholder', (sel.value || '') === '');
          }
          document.addEventListener('change', function(e) {
            if (e.target && e.target.matches('.home-quick select')) markPlaceholder(e.target);
          });
          document.querySelectorAll('.home-quick select').forEach(markPlaceholder);
        })();
      </script>
    </form>
  </section>
<?php return ob_get_clean();
});

/* 지역/테마/계절 칩 — [cg_quick_links]
   - groups: 표시 섹션 순서/선택(기본 'region,theme,season')
   - themes: 테마 섹션 화이트리스트(이름 기준, 비우면 전체)
   - seasons: 계절 섹션 화이트리스트(이름 기준, 기본 '봄꽃여행,여름물놀이,가을단풍명소,겨울눈꽃명소')
   예) [cg_quick_links themes="오토캠핑, 글램핑, 카라반, 반려동물 동반"]
*/
add_shortcode('cg_quick_links', function ($atts = []) {
  $a = shortcode_atts([
    'groups'  => 'region,theme,season',
    'themes'  => '',                                   // 이름 지정 시 해당 항목만
    'seasons' => '봄꽃여행, 여름물놀이, 가을단풍명소, 겨울눈꽃명소',
  ], $atts, 'cg_quick_links');

  $groups = array_values(array_filter(array_map('trim', explode(',', $a['groups']))));
  $base   = get_post_type_archive_link('campground');

  // 1) 지역(시도=상위) 불러오기
  $regions = get_terms(['taxonomy' => 'region', 'hide_empty' => false, 'parent' => 0, 'orderby' => 'name']);
  // 보이는 이름 단축 맵(선택)
  $name_map = [
    '서울특별시' => '서울',
    '경기도' => '경기',
    '인천광역시' => '인천',
    '강원도' => '강원',
    '충청북도' => '충북',
    '충청남도' => '충남',
    '전라북도' => '전북',
    '전라남도' => '전남',
    '경상북도' => '경북',
    '경상남도' => '경남',
    '제주특별자치도' => '제주',
    '부산광역시' => '부산',
    '대구광역시' => '대구',
    '대전광역시' => '대전',
    '광주광역시' => '광주',
    '울산광역시' => '울산',
    '세종특별자치시' => '세종'
  ];

  // 2) camp_theme 전체(테마/계절 선택에 공용)
  $themes_all = get_terms(['taxonomy' => 'camp_theme', 'hide_empty' => false, 'orderby' => 'name']);
  $themes_all = is_wp_error($themes_all) ? [] : $themes_all;

  // 편의: 이름 → term 객체 매핑
  $by_name = [];
  foreach ($themes_all as $t) {
    $by_name[$t->name] = $t;
  }

  // 2-1) 테마 섹션 후보
  $theme_whitelist = array_values(array_filter(array_map('trim', explode(',', $a['themes']))));
  if ($theme_whitelist) {
    $themes = array_values(array_filter(array_map(function ($nm) use ($by_name) {
      return $by_name[$nm] ?? null;
    }, $theme_whitelist)));
  } else {
    $themes = $themes_all; // 전부
  }

  // 2-2) 계절 섹션 후보(화이트리스트 우선, 없으면 키워드로 자동 추출)
  $season_names = array_values(array_filter(array_map('trim', explode(',', $a['seasons']))));
  $seasons = [];
  if ($season_names) {
    foreach ($season_names as $nm) {
      if (isset($by_name[$nm])) $seasons[] = $by_name[$nm];
    }
  }
  if (!$seasons) {
    // 자동: 이름에 '봄'/'여름'/'가을'/'겨울' 키워드 포함
    $seasons = array_values(array_filter($themes_all, function ($t) {
      return (mb_strpos($t->name, '봄') !== false) || (mb_strpos($t->name, '여름') !== false)
        || (mb_strpos($t->name, '가을') !== false) || (mb_strpos($t->name, '겨울') !== false);
    }));
  }

  ob_start(); ?>

  <?php foreach ($groups as $g):
    if ($g === 'region'): ?>
      <section class="home-grid" aria-label="지역별 보기">
        <h2 class="sec-title">지역별 보기</h2>
        <div class="grid">
          <?php if (!is_wp_error($regions)) foreach ($regions as $r): ?>
            <a class="card pill" href="<?php echo esc_url(add_query_arg('region', $r->slug, $base)); ?>">
              <?php echo esc_html($name_map[$r->name] ?? $r->name); ?>
            </a>
          <?php endforeach; ?>
        </div>
      </section>

    <?php elseif ($g === 'theme'): ?>
      <section class="home-grid" aria-label="테마별 보기">
        <h2 class="sec-title">테마별 보기</h2>
        <div class="grid">
          <?php foreach ($themes as $t): ?>
            <a class="card pill" href="<?php echo esc_url(add_query_arg('camp_theme', $t->slug, $base)); ?>">
              <?php echo esc_html($t->name); ?>
            </a>
          <?php endforeach; ?>
        </div>
      </section>

    <?php elseif ($g === 'season'): ?>
      <section class="home-grid" aria-label="계절별 보기">
        <h2 class="sec-title">계절별 보기</h2>
        <div class="grid">
          <?php foreach ($seasons as $t): ?>
            <a class="card pill" href="<?php echo esc_url(add_query_arg('camp_theme', $t->slug, $base)); ?>">
              <?php echo esc_html($t->name); ?>
            </a>
          <?php endforeach; ?>
        </div>
      </section>

  <?php endif;
  endforeach; ?>

<?php return ob_get_clean();
});

add_action('wp_footer', function () {
  if (!is_singular('campground')) return; ?>
  <script>
    document.addEventListener('DOMContentLoaded', function() {
      var detail = document.querySelector('.cg-detail');
      var mapWrap = document.querySelector('.cg-detail .cg-map-wrap');
      if (!detail || !mapWrap) return;

      // A) 상단(제목 줄) 잔여 '지도 크게 보기'/액션 제거
      var headerOpen = document.querySelector('.cg-detail .hd-row .open-map');
      if (headerOpen && headerOpen.parentElement) headerOpen.parentElement.removeChild(headerOpen);
      var hdActions = document.querySelector('.cg-detail .hd-actions');
      if (hdActions) hdActions.remove();

      // B) '인근 캠핑장' 섹션 찾기(여러 후보 + 타이틀 텍스트)
      function findRelatedBlock() {
        var sels = [
          '.cg-detail .related-camps-section',
          '.cg-detail #related-camps',
          '.cg-detail .related-camps',
          '.cg-detail .nearby-camps',
          '.cg-detail .nearby'
        ];
        for (var i = 0; i < sels.length; i++) {
          var el = document.querySelector(sels[i]);
          if (el) return el.closest('section') || el;
        }
        // 타이틀 텍스트 매칭(인근 캠핑장)
        var heads = detail.querySelectorAll('h2, h3, .section-title');
        for (var j = 0; j < heads.length; j++) {
          var h = heads[j];
          var txt = (h.textContent || '').replace(/\s+/g, '').trim();
          if (txt.indexOf('인근캠핑장') > -1) return h.closest('section') || h.parentElement;
        }
        return null;
      }

      // C) '사이트 구성' 마지막 요소
      var sitesList = detail.querySelectorAll('.sites');
      var lastSites = sitesList.length ? sitesList[sitesList.length - 1] : null;

      // D) 최종 이동: .sites 뒤 → 인근 캠핑장 앞 → 맨 아래
      var related = findRelatedBlock();
      if (lastSites && lastSites.parentNode) {
        lastSites.insertAdjacentElement('afterend', mapWrap);
      } else if (related && related.parentNode) {
        related.parentNode.insertBefore(mapWrap, related);
      } else {
        detail.appendChild(mapWrap);
      }

      // E) 지도 아래 액션바(좌: open-map / 우: back)
      var bar = mapWrap.querySelector('.map-actionbar');
      if (!bar) {
        bar = document.createElement('div');
        bar.className = 'map-actionbar';
        bar.innerHTML = '<div class="left"></div><div class="right"></div>';
        var old = mapWrap.querySelector('.cg-map-actions');
        if (old) old.replaceWith(bar);
        else mapWrap.appendChild(bar);
      }
      var leftBox = bar.querySelector('.left');
      var rightBox = bar.querySelector('.right');

      // 좌측: open-map(없으면 생성)
      var openBtn = mapWrap.querySelector('.open-map') || document.querySelector('.cg-detail .open-map');
      if (!openBtn) {
        var el = mapWrap.querySelector('#cg-map');
        if (el) {
          var addr = (el.dataset.address || '').trim();
          var lat = parseFloat(el.dataset.lat),
            lng = parseFloat(el.dataset.lng);
          var url = addr ? ('https://map.kakao.com/link/search/' + encodeURIComponent(addr)) :
            (!isNaN(lat) && !isNaN(lng) ? ('https://map.kakao.com/link/map/' + lat + ',' + lng) : '#');
          openBtn = document.createElement('a');
          openBtn.className = 'btn-ghost open-map';
          openBtn.href = url;
          openBtn.target = '_self';
          openBtn.rel = 'noopener';
          openBtn.textContent = '지도 크게 보기';
        }
      }
      if (openBtn) {
        if (openBtn.parentElement) openBtn.parentElement.removeChild(openBtn);
        leftBox.appendChild(openBtn);
      }

      // 우측: 목록으로(상단의 것을 내려보내거나 생성)
      var backBtn = document.querySelector('.cg-detail .btn-back');
      if (backBtn && backBtn.parentElement && !backBtn.closest('.map-actionbar')) {
        backBtn.parentElement.removeChild(backBtn);
      }
      if (!backBtn) {
        backBtn = document.createElement('a');
        backBtn.className = 'btn-ghost btn-back';
        backBtn.href = '/camping/'; // 아카이브 슬러그가 다르면 이 줄만 교체
        backBtn.textContent = '목록으로';
      }
      rightBox.appendChild(backBtn);
    });
  </script>
<?php }, 99);


// [사이트 구성] 표: 모바일 카드 라벨 보조(값 DOM은 건드리지 않음)
add_action('wp_footer', function () {
  if (!is_singular('campground')) return; ?>
  <script>
    document.addEventListener('DOMContentLoaded', function() {
      document.querySelectorAll('.cg-detail .sites .tbl').forEach(function(tbl) {
        if (tbl.classList.contains('js-labelled')) return; // 중복 방지

        // 헤더 수집(없으면 기본 라벨 사용)
        var heads = Array.from(tbl.querySelectorAll('thead th')).map(function(th) {
          return (th.textContent || '').trim();
        });
        if (!heads.length) heads = ['유형', '가로(m)', '세로(m)', '수량(면)'];

        var rows = tbl.querySelectorAll('tbody tr');
        rows.forEach(function(tr) {
          Array.from(tr.children).forEach(function(cell, idx) {
            if (!cell || (cell.tagName !== 'TD' && cell.tagName !== 'TH')) return;
            if (!cell.hasAttribute('data-label') && heads[idx]) {
              cell.setAttribute('data-label', heads[idx]);
            }
          });
        });

        tbl.classList.add('js-labelled');
      });
    });
  </script>
<?php }, 99);

// 캠핑장(CPT: campground) 목록에 ID 열 표시
add_filter('manage_edit-campground_columns', function ($cols) {
  // 첫 번째 열 뒤에 ID 넣기
  $new = [];
  $i = 0;
  foreach ($cols as $key => $label) {
    $new[$key] = $label;
    if ($i === 0) $new['cg_id'] = 'ID';
    $i++;
  }
  return $new;
});
add_action('manage_campground_posts_custom_column', function ($col, $post_id) {
  if ($col === 'cg_id') echo (int)$post_id;
}, 10, 2);
// 너비 살짝 조정(선택)
add_action('admin_head', function () {
  echo '<style>.post-type-campground .column-cg_id{width:80px}</style>';
});

/* 고캠핑 서비스키(반드시 URL 인코딩된 값) */
if (!defined('GOCAMPING_SERVICE_KEY')) {
  // 예) define('GOCAMPING_SERVICE_KEY', rawurlencode('발급받은원문키'));
  define('GOCAMPING_SERVICE_KEY', rawurlencode('35030fc12be6b9c642a0a7a35cf99d02d99d60bb23925748b360b63eb357014f')); // data.go.kr에서 발급, URL-encoded
}

/* 1) 전수 목록 페이지 호출 (기본목록 basedList) */
function gc_fetch_based_list($page = 1, $rows = 1000)
{
  $base = 'https://apis.data.go.kr/B551011/GoCamping/basedList';
  $qs = http_build_query(array(
    'serviceKey' => GOCAMPING_SERVICE_KEY,
    'MobileOS'   => 'ETC',
    'MobileApp'  => 'camp-sync',
    '_type'      => 'json',
    'numOfRows'  => $rows,
    'pageNo'     => $page,
  ));
  $url = $base . '?' . $qs;

  $res = wp_remote_get($url, array('timeout' => 20));
  if (is_wp_error($res)) return array(null, 0, 'HTTP_ERROR');

  $body = wp_remote_retrieve_body($res);
  $json = json_decode($body, true);
  if (!$json || empty($json['response']['body'])) return array(null, 0, 'EMPTY');

  $body = $json['response']['body'];
  $items = $body['items']['item'] ?? array();
  $total = intval($body['totalCount'] ?? 0);
  return array($items, $total, null);
}

/* 2) Haversine (km) */
function gc_haversine_km($lat1, $lng1, $lat2, $lng2)
{
  $R = 6371;
  $dLat = deg2rad($lat2 - $lat1);
  $dLng = deg2rad($lng2 - $lng1);
  $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
  return $R * (2 * atan2(sqrt($a), sqrt(1 - $a)));
}

/* 3) 원격 이미지 → 대표 이미지 설정 */
function gc_set_featured_from_url($post_id, $image_url, $source = '', $license = '')
{
  if (!$image_url) return false;
  $tmp = download_url($image_url);
  if (is_wp_error($tmp)) return false;

  $file_array = array(
    'name'     => basename(parse_url($image_url, PHP_URL_PATH)),
    'tmp_name' => $tmp,
  );
  $attach_id = media_handle_sideload($file_array, $post_id);
  if (is_wp_error($attach_id)) {
    @unlink($tmp);
    return false;
  }

  set_post_thumbnail($post_id, $attach_id);
  if ($source)  update_post_meta($post_id, '_image_source',   $source);
  if ($license) update_post_meta($post_id, '_image_license',  $license);
  update_post_meta($post_id, '_image_original', esc_url_raw($image_url));
  return true;
}

/* 4) 이름 유사도(간단 매칭용) */
function gc_name_similar($a, $b)
{
  $n1 = preg_replace('/\s+|[^가-힣a-z0-9]/iu', '', (string)$a);
  $n2 = preg_replace('/\s+|[^가-힣a-z0-9]/iu', '', (string)$b);
  if ($n1 === '' || $n2 === '') return 0;
  if ($n1 === $n2) return 100;
  if (mb_strpos($n1, $n2) !== false || mb_strpos($n2, $n1) !== false) return 80;
  return round(similar_text($n1, $n2) * 100 / max(mb_strlen($n1), mb_strlen($n2)));
}

/* 5) 한 item을 우리 글에 매칭 후 '빈 칸만' 보강 */
function gc_match_and_enrich_item($it, $radius_m = 1200, $dry_run = false)
{
  $name = trim($it['facltNm'] ?? '');
  $tel  = trim($it['tel'] ?? '');
  $hp   = trim($it['homepage'] ?? '');
  $addr = trim(($it['addr1'] ?? '') . ' ' . ($it['addr2'] ?? ''));
  $lng  = isset($it['mapX']) ? floatval($it['mapX']) : null; // 경도
  $lat  = isset($it['mapY']) ? floatval($it['mapY']) : null; // 위도
  $img  = $it['firstImageUrl'] ?? ($it['firstImageUrl2'] ?? '');
  $sbrs = trim($it['sbrsCl'] ?? '');

  if (!$name) return;

  // 제목으로 1차 후보(최대 5개)
  $maybe = get_posts(array(
    'post_type'      => 'campground',
    'posts_per_page' => 5,
    's'              => $name,
    'fields'         => 'ids',
    'no_found_rows'  => true,
  ));
  if (!$maybe) return;

  // 후보 중 가장 유사 + 반경 내
  $selected = 0;
  $bestScore = 0;
  foreach ($maybe as $pid) {
    $my_title = get_the_title($pid);
    $score = gc_name_similar($name, $my_title);

    if ($lat && $lng) {
      $my_lat = get_post_meta($pid, 'lat', true);
      $my_lng = get_post_meta($pid, 'lng', true);
      if ($my_lat && $my_lng) {
        $dist_m = gc_haversine_km(floatval($my_lat), floatval($my_lng), $lat, $lng) * 1000;
        if ($dist_m > $radius_m) continue;
      }
    }
    if ($score > $bestScore) {
      $bestScore = $score;
      $selected = $pid;
    }
  }
  if (!$selected || $bestScore < 50) return; // 너무 다르면 보강하지 않음

  if ($dry_run) return; // 드라이런은 저장 없이 종료

  // 메타 보강(빈 칸만)
  if ($tel && !get_post_meta($selected, 'phone', true))     update_post_meta($selected, 'phone', $tel);
  if ($hp  && !get_post_meta($selected, 'homepage', true))  update_post_meta($selected, 'homepage', esc_url_raw($hp));
  if ($sbrs && !get_post_meta($selected, 'features', true)) update_post_meta($selected, 'features', $sbrs);
  if ($addr && !get_post_meta($selected, 'address_gc', true)) update_post_meta($selected, 'address_gc', $addr);

  if ($img && !has_post_thumbnail($selected)) {
    gc_set_featured_from_url($selected, $img, '고캠핑(한국관광공사)', '출처표시');
  }
}

/* 6) WP-CLI: 전수 업데이트 (총 건수 자동 확인 → 전체 페이지 순회) */
if (defined('WP_CLI') && WP_CLI) {
  /**
   * 고캠핑 전수 동기화
   * 사용: wp gc sync-all [--rows=1000] [--start=1] [--end=N] [--radius=1200] [--dry-run]
   * - rows: 페이지당 건수(기본 1000)
   * - start/end: 페이지 범위를 수동 지정(없으면 totalCount 기준 자동 전체)
   * - radius: 매칭 반경(m), 기본 1200m
   * - dry-run: 저장 없이 매칭만 수행(테스트용)
   */
  WP_CLI::add_command('gc sync-all', function ($args, $assoc) {
    $rows   = intval($assoc['rows'] ?? 1000);
    $start  = isset($assoc['start']) ? max(1, intval($assoc['start'])) : null;
    $end    = isset($assoc['end'])   ? max(1, intval($assoc['end']))   : null;
    $radius = intval($assoc['radius'] ?? 1200);
    $dry    = isset($assoc['dry-run']);

    // 총건수 확인
    list($items, $total, $err) = gc_fetch_based_list(1, $rows);
    if ($err) WP_CLI::error("페이지 1 응답 오류: $err");
    $total = intval($total);
    if ($total <= 0) WP_CLI::error('totalCount=0');

    $pages = (int)ceil($total / $rows);
    $p_from = $start ?: 1;
    $p_to   = $end   ?: $pages;

    WP_CLI::log("총 {$total}건 / rows={$rows} → 총 페이지 {$pages} (실행: {$p_from}~{$p_to})");
    // 1페이지는 이미 items가 있으니 재호출 없이 처리
    for ($p = $p_from; $p <= $p_to; $p++) {
      if ($p == 1 && $items) {
        WP_CLI::log("페이지 1 처리 중 … (" . count($items) . "건)");
        foreach ($items as $it) gc_match_and_enrich_item($it, $radius, $dry);
      } else {
        list($items2, $total2, $err2) = gc_fetch_based_list($p, $rows);
        if ($err2 || !$items2) {
          WP_CLI::warning("페이지 {$p} 응답 없음/오류");
          continue;
        }
        WP_CLI::log("페이지 {$p} 처리 중 … (" . count($items2) . "건)");
        foreach ($items2 as $it) gc_match_and_enrich_item($it, $radius, $dry);
      }
      WP_CLI::success("페이지 {$p} 완료");
      usleep(120000); // 0.12s(선택): API 예의상 아주 짧은 간격
    }
    WP_CLI::success('전수 동기화 완료');
  });
}

// 안전 tel: 링크 변환
if (!function_exists('cg_tel_href')) {
  function cg_tel_href($s)
  {
    $d = preg_replace('/[^0-9+]/', '', (string)$s);
    return $d ? 'tel:' . $d : '';
  }
}

// 대표 이미지/외부 원본을 본문 상단에 넣을 블록 생성
function cg_build_image_block($post_id)
{
  // 대표 이미지가 있으면 그걸 사용
  if (has_post_thumbnail($post_id)) {
    $img = wp_get_attachment_image_src(get_post_thumbnail_id($post_id), 'large');
    if ($img && !empty($img[0])) {
      $alt = get_the_title($post_id);
      return '<figure class="cg-hero" style="margin:0 0 16px 0;"><img src="' . esc_url($img[0]) . '" alt="' . esc_attr($alt) . '" loading="lazy" decoding="async" style="width:100%;height:auto;border-radius:8px;"></figure>';
    }
  }
  // 대표 이미지 없으면 고캠핑 원본(_image_original) 폴백
  $ext = get_post_meta($post_id, '_image_original', true);
  if ($ext) {
    $alt = get_the_title($post_id);
    return '<figure class="cg-hero" style="margin:0 0 16px 0;"><img src="' . esc_url($ext) . '" alt="' . esc_attr($alt) . '" loading="lazy" decoding="async" style="width:100%;height:auto;border-radius:8px;"></figure>';
  }
  return '';
}

// “핵심 정보” 블록 생성(주소·전화·홈페이지·인허가일 등)
function cg_build_keyinfo_block($post_id)
{
  $addr = get_post_meta($post_id, 'address', true);
  if (!$addr) $addr = get_post_meta($post_id, 'address_gc', true); // 고캠핑 보강 주소 폴백
  $tel  = get_post_meta($post_id, 'phone', true);
  $home = get_post_meta($post_id, 'homepage', true);

  // 인허가일 유연 탐색(있을 때만 표시)
  $permit = '';
  foreach (['permit_date', 'approval_date', 'lic_date', '인허가일'] as $k) {
    $v = trim((string)get_post_meta($post_id, $k, true));
    if ($v) {
      $permit = $v;
      break;
    }
  }

  // 값이 하나도 없으면 블록 자체를 만들지 않음
  if (!$addr && !$tel && !$home && !$permit) return '';

  ob_start(); ?>
  <section class="key-info" aria-label="핵심 정보" style="margin:0 0 16px 0;">
    <h2 class="sec-title">핵심 정보</h2>
    <ul class="spec">
      <?php if ($addr): ?>
        <li class="spec-item">
          <span class="k">주소</span>
          <span class="v"><?php echo esc_html($addr); ?></span>
        </li>
      <?php endif; ?>
      <?php if ($tel): ?>
        <li class="spec-item">
          <span class="k">전화</span>
          <span class="v"><a href="<?php echo esc_attr(cg_tel_href($tel)); ?>"><?php echo esc_html($tel); ?></a></span>
        </li>
      <?php endif; ?>
      <?php if ($home): ?>
        <li class="spec-item">
          <span class="k">홈페이지</span>
          <span class="v"><a href="<?php echo esc_url($home); ?>" target="_blank" rel="noopener">바로가기</a></span>
        </li>
      <?php endif; ?>
      <?php if ($permit): ?>
        <li class="spec-item">
          <span class="k">인허가일</span>
          <span class="v"><?php echo esc_html($permit); ?></span>
        </li>
      <?php endif; ?>
    </ul>
  </section>
<?php
  return ob_get_clean();
}

// 본문 재구성(상단 이미지 + 핵심 정보 + 기존 본문)
function cg_rebuild_content($post_id, $opts = [])
{
  $opts = wp_parse_args($opts, [
    'include_image' => true,   // 상단 이미지 블록 포함
    'prepend_only'  => true,   // 기존 본문은 그대로 두고 앞에만 붙임
  ]);
  $old = get_post_field('post_content', $post_id);

  $parts = [];
  if ($opts['include_image']) {
    $img = cg_build_image_block($post_id);
    if ($img) $parts[] = $img;
  }
  $parts[] = cg_build_keyinfo_block($post_id);

  // 기존 본문 유지(앞에 핵심 정보만 프리펜드)
  if ($opts['prepend_only']) {
    $parts[] = $old;
  }
  $new = trim(implode("\n\n", array_filter($parts)));

  // 만약 prepend_only=false 라면, 필요 시 old를 뒤에 합치지 않고 템플릿만 사용 가능
  return $new ?: $old;
}

// WP‑CLI: 전 건 재임포트
if (defined('WP_CLI') && WP_CLI) {
  /**
   * 캠핑장 본문 재임포트(상단 이미지 + 핵심 정보 + 기존 본문 유지)
   * 사용:
   *  - wp cg reimport-content --all
   *  - wp cg reimport-content --ids=12,34,56
   *  - wp cg reimport-content --no-image (이미지 블록 생략)
   *  - wp cg reimport-content --replace (기존 본문 무시하고 템플릿만 사용)
   *  - wp cg reimport-content --dry-run (미리보기만)
   */
  WP_CLI::add_command('cg reimport-content', function ($args, $assoc) {
    $ids = [];
    if (!empty($assoc['ids'])) {
      $ids = array_map('intval', explode(',', $assoc['ids']));
    } else {
      if (empty($assoc['all'])) WP_CLI::error('사용법: --all 또는 --ids=1,2,3');
      $ids = get_posts(['post_type' => 'campground', 'posts_per_page' => -1, 'fields' => 'ids', 'post_status' => 'any']);
    }

    $include_image = !isset($assoc['no-image']);
    $prepend_only  = !isset($assoc['replace']);
    $dry           = isset($assoc['dry-run']);

    $n = 0;
    foreach ($ids as $pid) {
      $new = cg_rebuild_content($pid, [
        'include_image' => $include_image,
        'prepend_only'  => $prepend_only,
      ]);
      if ($dry) {
        $len = mb_strlen(wp_strip_all_tags($new));
        WP_CLI::log("DRY #{$pid}: length={$len}");
        continue;
      }
      wp_update_post(['ID' => $pid, 'post_content' => $new], true);
      if (is_wp_error($e = wp_update_post(['ID' => $pid, 'post_content' => $new], true))) {
        WP_CLI::warning("FAIL #{$pid}: " . $e->get_error_message());
      } else {
        $n++;
      }
      usleep(80000); // 0.08s 살짝 딜레이(안정성)
    }
    WP_CLI::success("재임포트 완료: {$n}건");
  });
}
// 절대 URL 판별
function cg_is_abs_url($u)
{
  return is_string($u) && preg_match('#^https?://#i', $u);
}

// Referer를 붙여 파일을 받아 임시 파일로 저장(성공 시 임시 경로 반환)
function cg_download_with_referer($url, $referer = 'https://gocamping.or.kr/')
{
  if (!cg_is_abs_url($url)) return new WP_Error('bad_url', 'URL이 절대경로가 아닙니다.');
  $tmp = wp_tempnam($url);
  if (!$tmp) return new WP_Error('tmp_fail', '임시 파일 생성 실패');

  $res = wp_remote_get($url, array(
    'timeout'  => 20,
    'stream'   => true,
    'filename' => $tmp,
    'headers'  => array('Referer' => $referer),
  ));
  if (is_wp_error($res)) {
    @unlink($tmp);
    return $res;
  }
  $code = wp_remote_retrieve_response_code($res);
  if ($code !== 200) {
    @unlink($tmp);
    return new WP_Error('http_' . $code, '다운로드 실패: ' . $code);
  }
  return $tmp;
}

// 외부 URL을 내려받아 대표 이미지로 설정(Referer 포함)
function cg_set_featured_from_url_strict($post_id, $url)
{
  if (has_post_thumbnail($post_id)) return true; // 이미 대표 이미지 있음
  $tmp = cg_download_with_referer($url);
  if (is_wp_error($tmp)) return false;

  $file_array = array(
    'name'     => basename(parse_url($url, PHP_URL_PATH)) ?: 'image.jpg',
    'tmp_name' => $tmp,
  );
  $attach_id = media_handle_sideload($file_array, $post_id);
  if (is_wp_error($attach_id)) {
    @unlink($tmp);
    return false;
  }

  set_post_thumbnail($post_id, $attach_id);
  if (!get_post_meta($post_id, '_image_source', true))  update_post_meta($post_id, '_image_source', '고캠핑(한국관광공사)');
  if (!get_post_meta($post_id, '_image_license', true)) update_post_meta($post_id, '_image_license', '출처표시');
  if (!get_post_meta($post_id, '_image_original', true)) update_post_meta($post_id, '_image_original', esc_url_raw($url));
  return true;
}

// 본문 상단 히어로: 대표 이미지 우선, 외부 URL은 절대경로일 때만 사용
if (!function_exists('cg_build_hero_img_html')) {
  function cg_build_hero_img_html($post_id)
  {
    if (!has_post_thumbnail($post_id)) return '';
    $aid = get_post_thumbnail_id($post_id);
    $src = wp_get_attachment_image_url($aid, 'large');
    if (!$src) $src = wp_get_attachment_image_url($aid, 'medium_large');
    if (!$src) $src = wp_get_attachment_image_url($aid, 'full');
    if (!$src) return '';
    $alt = esc_attr(get_the_title($post_id));
    return '<figure class="cg-hero"><img class="cg-hero-img" src="' . esc_url($src) . '" alt="' . $alt . '" loading="lazy" decoding="async"></figure>';
  }
}

// WP-CLI: 본문 정규화(히어로만 정리)
if (defined('WP_CLI') && WP_CLI) {
  WP_CLI::add_command('cg normalize', function ($args, $assoc) {
    // 대상 ID 수집
    if (!empty($assoc['ids'])) {
      $ids = array_map('intval', explode(',', $assoc['ids']));
    } else {
      if (empty($assoc['all'])) WP_CLI::error('사용법: --all 또는 --ids=1,2');
      $ids = get_posts(['post_type' => 'campground', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids']);
    }

    $updated = 0;
    $skipped = 0;
    foreach ($ids as $pid) {
      $html = get_post_field('post_content', $pid);

      // 1) 예전 히어로(인라인 포함)를 통으로 제거
      $removed = 0;
      $html = preg_replace('#<figure[^>]*class="[^"]*cg-hero[^"]*"[^>]*>.*?</figure>#is', '', $html, -1, $removed);

      // 2) 본문이 이미지/figure로 시작하면 새 히어로는 붙이지 않음
      $starts_with_media = (bool)preg_match('#^\s*<(figure|img)\b#i', ltrim($html));

      // 3) 새 히어로(인라인 없는 버전) 생성
      $hero = '';
      if (!$starts_with_media) {
        $hero = cg_build_hero_img_html($pid); // 반드시 함수 호출
      }

      // 4) 변경 없으면 스킵
      if (!$removed && $hero === '') {
        $skipped++;
        continue;
      }

      // 5) 저장
      $new = ($hero ? $hero . "\n\n" : '') . $html;
      $res = wp_update_post(['ID' => $pid, 'post_content' => $new], true);
      if (!is_wp_error($res)) $updated++;

      usleep(60000);
    }
    WP_CLI::success("정규화: 갱신 {$updated}, 건너뜀 {$skipped}");
  });
}

// 절대 URL 판별
if (!function_exists('cg_is_abs_url')) {
  function cg_is_abs_url($u)
  {
    return is_string($u) && preg_match('#^https?://#i', $u);
  }
}

// Referer 포함 다운로드 → 대표 이미지 설정(안티핫링크 회피)
if (!function_exists('cg_download_with_referer')) {
  function cg_download_with_referer($url, $referer = 'https://gocamping.or.kr/')
  {
    if (!cg_is_abs_url($url)) return new WP_Error('bad_url', 'URL이 절대경로가 아닙니다.');
    $tmp = wp_tempnam($url);
    if (!$tmp) return new WP_Error('tmp_fail', '임시 파일 생성 실패');
    $res = wp_remote_get($url, [
      'timeout' => 20,
      'stream' => true,
      'filename' => $tmp,
      'headers' => ['Referer' => $referer],
    ]);
    if (is_wp_error($res)) {
      @unlink($tmp);
      return $res;
    }
    $code = wp_remote_retrieve_response_code($res);
    if ($code !== 200) {
      @unlink($tmp);
      return new WP_Error('http_' . $code, '다운로드 실패: ' . $code);
    }
    return $tmp;
  }
}

if (!function_exists('cg_set_featured_from_url_strict')) {
  function cg_set_featured_from_url_strict($post_id, $url)
  {
    $tmp = cg_download_with_referer($url);
    if (is_wp_error($tmp)) return false;
    $file_array = ['name' => basename(parse_url($url, PHP_URL_PATH)) ?: 'image.jpg', 'tmp_name' => $tmp];
    $attach_id = media_handle_sideload($file_array, $post_id);
    if (is_wp_error($attach_id)) {
      @unlink($tmp);
      return false;
    }
    set_post_thumbnail($post_id, $attach_id);
    if (!get_post_meta($post_id, '_image_source', true))  update_post_meta($post_id, '_image_source', '고캠핑(한국관광공사)');
    if (!get_post_meta($post_id, '_image_license', true)) update_post_meta($post_id, '_image_license', '출처표시');
    if (!get_post_meta($post_id, '_image_original', true)) update_post_meta($post_id, '_image_original', esc_url_raw($url));
    return true;
  }
}
// 절대 URL 판별
if (!function_exists('cg_is_abs_url')) {
  function cg_is_abs_url($u)
  {
    return is_string($u) && preg_match('#^https?://#i', $u);
  }
}

// Referer 포함 다운로드 → 대표 이미지 설정(안티핫링크 회피)
if (!function_exists('cg_download_with_referer')) {
  function cg_download_with_referer($url, $referer = 'https://gocamping.or.kr/')
  {
    if (!cg_is_abs_url($url)) return new WP_Error('bad_url', 'URL이 절대경로가 아닙니다.');
    $tmp = wp_tempnam($url);
    if (!$tmp) return new WP_Error('tmp_fail', '임시 파일 생성 실패');
    $res = wp_remote_get($url, [
      'timeout' => 20,
      'stream' => true,
      'filename' => $tmp,
      'headers' => ['Referer' => $referer],
    ]);
    if (is_wp_error($res)) {
      @unlink($tmp);
      return $res;
    }
    $code = wp_remote_retrieve_response_code($res);
    if ($code !== 200) {
      @unlink($tmp);
      return new WP_Error('http_' . $code, '다운로드 실패: ' . $code);
    }
    return $tmp;
  }
}

if (!function_exists('cg_set_featured_from_url_strict')) {
  function cg_set_featured_from_url_strict($post_id, $url)
  {
    $tmp = cg_download_with_referer($url);
    if (is_wp_error($tmp)) return false;
    $file_array = ['name' => basename(parse_url($url, PHP_URL_PATH)) ?: 'image.jpg', 'tmp_name' => $tmp];
    $attach_id = media_handle_sideload($file_array, $post_id);
    if (is_wp_error($attach_id)) {
      @unlink($tmp);
      return false;
    }
    set_post_thumbnail($post_id, $attach_id);
    if (!get_post_meta($post_id, '_image_source', true))  update_post_meta($post_id, '_image_source', '고캠핑(한국관광공사)');
    if (!get_post_meta($post_id, '_image_license', true)) update_post_meta($post_id, '_image_license', '출처표시');
    if (!get_post_meta($post_id, '_image_original', true)) update_post_meta($post_id, '_image_original', esc_url_raw($url));
    return true;
  }
}

// WP‑CLI: _image_original(등)에서 내려받아 대표 이미지로 설정
if (defined('WP_CLI') && WP_CLI) {
  WP_CLI::add_command('cg hero-from-meta', function ($args, $assoc) {
    $ids = [];
    if (!empty($assoc['ids'])) {
      $ids = array_map('intval', explode(',', $assoc['ids']));
    } else {
      if (empty($assoc['all'])) WP_CLI::error('사용법: --all 또는 --ids=1,2');
      $ids = get_posts(['post_type' => 'campground', 'posts_per_page' => -1, 'fields' => 'ids', 'post_status' => 'any']);
    }

    // 우선순위: 옵션으로 지정된 키가 있으면 최우선
    $keys = !empty($assoc['meta-key'])
      ? [$assoc['meta-key']]
      : ['_image_original', 'image_original', 'firstImageUrl', 'firstImageUrl2'];

    $force = !empty($assoc['force']);
    $done = 0;
    $skip = 0;
    $fail = 0;

    foreach ($ids as $pid) {
      if (has_post_thumbnail($pid) && !$force) {
        $skip++;
        continue;
      }

      // 메타키 순회로 첫 유효 URL 선택
      $url = '';
      foreach ($keys as $k) {
        $u = trim((string)get_post_meta($pid, $k, true));
        if ($u !== '') {
          $url = $u;
          break;
        }
      }
      if (!cg_is_abs_url($url)) {
        $skip++;
        continue;
      }

      $ok = cg_set_featured_from_url_strict($pid, $url);
      if ($ok) $done++;
      else $fail++;
      usleep(80000);
    }
    WP_CLI::success("대표 이미지 설정: 성공 {$done}, 건너뜀 {$skip}, 실패 {$fail}");
  });
}

// cg normalize: 본문 상단에 대표 이미지(히어로) 1장만 안전하게 붙이는 커맨드
if (defined('WP_CLI') && WP_CLI) {
  WP_CLI::add_command('cg normalize', function ($args, $assoc) {
    // 대상 ID 결정
    if (!empty($assoc['ids'])) {
      $ids = array_map('intval', explode(',', $assoc['ids']));
    } else {
      if (empty($assoc['all'])) WP_CLI::error('사용법: --all 또는 --ids=1,2');
      $ids = get_posts(['post_type' => 'campground', 'posts_per_page' => -1, 'fields' => 'ids', 'post_status' => 'any']);
    }

    $updated = 0;
    $skipped = 0;
    foreach ($ids as $pid) {
      // 현재 본문 가져오기
      $html = get_post_field('post_content', $pid);

      // 1) 이전에 넣었을 수 있는 our hero(figure.cg-hero) 제거(중복 방지)
      $removed = 0;
      $html = preg_replace('#<figure[^>]*class="[^"]*\bcg-hero\b[^"]*"[^>]*>.*?</figure>#is', '', $html, -1, $removed);

      // 2) 대표 이미지가 있을 때만 히어로 준비
      $prepend = '';
      if (has_post_thumbnail($pid)) {
        $src = wp_get_attachment_image_url(get_post_thumbnail_id($pid), 'large');
        if ($src) {
          // 이미 본문이 이미지/figure로 시작하면 추가하지 않음
          $starts_with_img = (bool)preg_match('#^\s*<(figure|img)\b#i', ltrim($html));
          if (!$starts_with_img) {
            $alt = esc_attr(get_the_title($pid));
            $img = '<figure class="cg-hero" style="margin:0 0 16px 0;"><img src="' . esc_url($src) . '" alt="' . $alt . '" loading="lazy" decoding="async" style="width:100%;height:auto;border-radius:8px;"></figure>';
            $prepend = $img . "\n\n";
          }
        }
      }

      // 3) 갱신 필요 없으면 스킵
      if (!$removed && $prepend === '') {
        $skipped++;
        continue;
      }

      // 4) 저장
      $new = $prepend . $html;
      $res = wp_update_post(['ID' => $pid, 'post_content' => $new], true);
      if (is_wp_error($res)) {
        WP_CLI::warning("FAIL #{$pid}: " . $res->get_error_message());
      } else {
        $updated++;
      }
      usleep(60000);
    }
    WP_CLI::success("정규화: 갱신 {$updated}, 건너뜀 {$skipped}");
  });
}

// 캠핑장 목록/단일 화면에만 스킨 클래스 부여
add_filter('body_class', function ($classes) {
  if (is_post_type_archive('campground') || is_singular('campground')) {
    $classes[] = 'cg-skin';
  }
  return $classes;
});
