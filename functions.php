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
