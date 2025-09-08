<?php

/**
 * Campground Archive (2단계 지역 필터: 시도/시군구)
 * 경로: wp-content/themes/astra-child/archive-campground.php
 */
get_header();

/* 현재 선택값 받기(퍼센트 인코딩 보존) */
$cur_region = isset($_GET['region'])     ? (string) wp_unslash($_GET['region'])     : '';
$cur_do     = isset($_GET['region_do'])  ? (string) wp_unslash($_GET['region_do'])  : '';
$cur_si     = isset($_GET['region_si'])  ? (string) wp_unslash($_GET['region_si'])  : '';
$cur_theme  = isset($_GET['camp_theme']) ? (string) wp_unslash($_GET['camp_theme']) : '';

// region만 넘어왔을 때 시도/시군구 유추
if ($cur_region && !$cur_do && !$cur_si) {
  $t = get_term_by('slug', $cur_region, 'region');
  if ($t) {
    if ($t->parent) { // 자식(시군구)
      $cur_si = $t->slug;
      $p = get_term($t->parent, 'region');
      if ($p && !is_wp_error($p)) $cur_do = $p->slug;
    } else {
      $cur_do = $t->slug; // 부모(시도)
    }
  }
}

/* camp_theme: 슬러그/이름 모두 지원 (홈에서 이름이 넘어와도 셋팅되도록) */
if ($cur_theme !== '') {
  $cur_theme = rawurldecode($cur_theme); // 혹시 퍼센트 인코딩일 때 대비
  $term = get_term_by('slug', $cur_theme, 'camp_theme');
  if (!$term) $term = get_term_by('name', $cur_theme, 'camp_theme');
  if ($term && !is_wp_error($term)) {
    $cur_theme = $term->slug;            // 이후 selected/페이징은 슬러그 기준
  } else {
    $cur_theme = '';
  }
}

/* 유효성: 없는 슬러그가 넘어오면 초기화 */
if ($cur_do && ! get_term_by('slug', $cur_do, 'region'))        $cur_do = '';
if ($cur_si && ! get_term_by('slug', $cur_si, 'region'))        $cur_si = '';
if ($cur_region && ! get_term_by('slug', $cur_region, 'region')) $cur_region = '';
if ($cur_theme && ! get_term_by('slug', $cur_theme, 'camp_theme')) $cur_theme = '';

/* 시도/시군구 맵 구성(부모: parent=0) */
$provinces = get_terms(array(
  'taxonomy'   => 'region',
  'hide_empty' => false,
  'parent'     => 0,
  'orderby'    => 'name',
));
$region_map      = array(); // [parent_slug => [ ['slug'=>child_slug,'name'=>child_name], ... ]]
$parent_of_child = array(); // [child_slug  => parent_slug]

if (!is_wp_error($provinces)) {
  foreach ($provinces as $p) {
    $children = get_terms(array(
      'taxonomy'   => 'region',
      'hide_empty' => false,
      'parent'     => $p->term_id,
      'orderby'    => 'name',
    ));
    $region_map[$p->slug] = array();
    if (!is_wp_error($children)) {
      foreach ($children as $c) {
        $region_map[$p->slug][] = array('slug' => $c->slug, 'name' => $c->name);
        $parent_of_child[$c->slug] = $p->slug;
      }
    }
  }
}

/* 최종 region(서버 검색용)은 "시군구가 선택되면 시군구, 아니면 시도" */
$region_final = $cur_si ?: $cur_do;

/* 아카이브 기본 URL */
$archive_link = get_post_type_archive_link('campground');
?>

<main id="primary" class="site-main">

  <header class="page-header">
    <h1 class="page-title">캠핑장 목록</h1>
    <?php if ($region_final || $cur_theme): ?>
      <p class="ast-archive-description">
        <?php
        $parts = array();
        if ($region_final) {
          $rt = get_term_by('slug', $region_final, 'region');
          $parts[] = '지역: ' . esc_html($rt ? $rt->name : $region_final);
        }
        if ($cur_theme) {
          $rt = get_term_by('slug', $cur_theme, 'camp_theme');
          $parts[] = '테마: ' . esc_html($rt ? $rt->name : $cur_theme);
        }
        echo implode(' · ', $parts);
        ?>
      </p>
    <?php endif; ?>
  </header>

  <!-- 필터 바 -->
  <div class="camp-filter">
    <form id="camp-filter-form" method="get" action="<?php echo esc_url($archive_link); ?>">

      <!-- 시도(부모) -->
      <label for="filter-province" class="screen-reader-text">시도 선택</label>
      <div class="sel">
        <select id="filter-province" name="region_do" aria-label="시도">
          <option value=""><?php echo esc_html__('시도 전체', 'astra'); ?></option>
          <?php
          if (!is_wp_error($provinces)) {
            foreach ($provinces as $p) {
              printf(
                '<option value="%s" %s>%s</option>',
                esc_attr($p->slug),
                selected($cur_do, $p->slug, false),
                esc_html($p->name)
              );
            }
          }
          ?>
        </select>
      </div>

      <!-- 시군구(자식) -->
      <label for="filter-city" class="screen-reader-text">시군구 선택</label>
      <div class="sel">
        <select id="filter-city" name="region_si" aria-label="시군구">
          <option value=""><?php echo esc_html__('시군구 전체', 'astra'); ?></option>
          <?php
          // 서버사이드 초기 렌더(선택된 시도의 자식만)
          if ($cur_do && !empty($region_map[$cur_do])) {
            foreach ($region_map[$cur_do] as $c) {
              printf(
                '<option value="%s" %s>%s</option>',
                esc_attr($c['slug']),
                selected($cur_si, $c['slug'], false),
                esc_html($c['name'])
              );
            }
          }
          ?>
        </select>
      </div>

      <!-- 테마 -->
      <label for="filter-theme" class="screen-reader-text">테마 선택</label>
      <div class="sel">
        <select id="filter-theme" name="camp_theme" aria-label="테마">
          <option value=""><?php echo esc_html__('테마 전체', 'astra'); ?></option>
          <?php
          $themes = get_terms(array('taxonomy' => 'camp_theme', 'hide_empty' => false, 'orderby' => 'name'));
          if (!is_wp_error($themes)) {
            foreach ($themes as $t) {
              printf(
                '<option value="%s" %s>%s</option>',
                esc_attr($t->slug),
                selected($cur_theme, $t->slug, false),
                esc_html($t->name)
              );
            }
          }
          ?>
        </select>
      </div>

      <!-- 서버에서 쓰는 최종 region 파라미터 -->
      <input type="hidden" name="region" id="region-final" value="<?php echo esc_attr($region_final); ?>">

      <div class="filter-actions">
        <button type="submit" class="ast-button btn-primary">필터 적용</button>
        <?php if ($region_final || $cur_theme || $cur_do || $cur_si): ?>
          <a class="btn-ghost reset-btn" href="<?php echo esc_url($archive_link); ?>">초기화</a>
        <?php endif; ?>
      </div>

      <!-- 시도/시군구 의존 콤보 동작 스크립트 -->
      <script>
        (function() {
          var REGION_MAP = <?php echo wp_json_encode($region_map, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
          var $do = document.getElementById('filter-province');
          var $si = document.getElementById('filter-city');
          var $fin = document.getElementById('region-final');

          function rebuildCityOptions(provSlug, selectedCity) {
            var def = document.createElement('option');
            def.value = '';
            def.textContent = '시군구 전체';
            var frag = document.createDocumentFragment();
            frag.appendChild(def);

            var list = REGION_MAP[provSlug] || [];
            list.forEach(function(c) {
              var opt = document.createElement('option');
              opt.value = c.slug;
              opt.textContent = c.name;
              if (selectedCity && selectedCity === c.slug) opt.selected = true;
              frag.appendChild(opt);
            });

            while ($si.firstChild) $si.removeChild($si.firstChild);
            $si.appendChild(frag);
          }

          // 시도 변경 시
          $do.addEventListener('change', function() {
            var p = (this.value || '').trim();
            rebuildCityOptions(p, '');
            // 최종 region: 시군구 선택이 없으면 시도
            $fin.value = p || '';
          });

          // 시군구 변경 시
          $si.addEventListener('change', function() {
            var c = (this.value || '').trim();
            $fin.value = c ? c : ($do.value || '');
          });

          // 초기 동기화(뒤로가기/새로고침 대비)
          (function init() {
            var c = ($si.value || '').trim();
            var p = ($do.value || '').trim();
            $fin.value = c ? c : (p ? p : '');
          })();
        })();
      </script>
    </form>
  </div>

  <?php if (have_posts()) : ?>
    <div class="ast-row">
      <?php while (have_posts()) : the_post(); ?>
        <?php
        $pid    = get_the_ID();
        $has_thumb = has_post_thumbnail($pid);
        $r_terms = get_the_terms($pid, 'region');
        $t_terms = get_the_terms($pid, 'camp_theme');

        // 배지 HTML(디자인 유지)
        $badges = '';
        if ($r_terms && !is_wp_error($r_terms)) {
          foreach ($r_terms as $rt) {
            $badges .= '<span class="badge">' . esc_html($rt->name) . '</span> ';
          }
        }
        if ($t_terms && !is_wp_error($t_terms)) {
          foreach ($t_terms as $tt) {
            $badges .= '<span class="badge theme">' . esc_html($tt->name) . '</span> ';
          }
        }
        ?>
        <?php if (!$has_thumb): ?>

          <!-- 썸네일이 없을 때: 기존 코드 그대로 -->
          <article id="post-<?php the_ID(); ?>" <?php post_class('camp-item no-thumb'); ?>>
            <h2 class="entry-title"><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h2>
            <?php if ($badges): ?>
              <div class="card-badges"><?php echo $badges; ?></div>
            <?php endif; ?>
          </article>

        <?php else: ?>

          <!-- 썸네일이 있을 때: 같은 카드 디자인 + 내부만 2열 -->
          <article id="post-<?php the_ID(); ?>" <?php post_class('camp-item has-thumb'); ?>>

            <div class="cg-row">
              <a class="cg-thumb" href="<?php the_permalink(); ?>" aria-label="<?php the_title_attribute(); ?>">
                <?php the_post_thumbnail('medium_large', ['loading' => 'lazy', 'decoding' => 'async']); ?>
              </a>

              <div class="cg-body">
                <h2 class="entry-title"><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h2>
                <?php if ($badges): ?>
                  <div class="card-badges"><?php echo $badges; ?></div>
                <?php endif; ?>
              </div>
            </div>

          </article>

        <?php endif; ?>
      <?php endwhile; ?>
    </div>

    <?php
    // 페이징: 필터 유지
    the_posts_pagination(array(
      'mid_size'           => 1,     // 현재 페이지 양옆 1개씩만
      'end_size'           => 1,     // 처음·끝 1개
      'prev_text'          => '‹',   // 이전 ← 화살표
      'next_text'          => '›',   // 다음 → 화살표
      'screen_reader_text' => '',    // SR용 텍스트(시각 표시는 감춤)
      'add_args'           => array_filter(array(
        'region'     => $region_final ?? '',
        'region_do'  => $cur_do ?? '',
        'region_si'  => $cur_si ?? '',
        'camp_theme' => $cur_theme ?? '',
        'pet'        => $cur_pet ?? '',
      )),
    ));
    ?>

  <?php else : ?>
    <p class="no-result">조건에 맞는 캠핑장이 없습니다.</p>
  <?php endif; ?>

</main>

<?php get_footer();
