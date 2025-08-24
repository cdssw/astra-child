<?php

/**
 * Archive template for Campground (캠핑장 목록, 썸네일 없음)
 */
get_header(); ?>

<main id="primary" class="site-main">

  <header class="page-header">
    <h1 class="page-title">캠핑장 목록</h1>

    <!-- 상단 가로형 필터 -->
    <form method="get" action="<?php echo esc_url(get_post_type_archive_link('campground')); ?>" class="camp-filter">
      <?php
      $cur_r = isset($_GET['region']) ? sanitize_text_field($_GET['region']) : '';
      $cur_t = isset($_GET['camp_theme']) ? sanitize_text_field($_GET['camp_theme']) : '';

      $regions = get_terms(array('taxonomy' => 'region', 'hide_empty' => false));
      $themes  = get_terms(array('taxonomy' => 'camp_theme', 'hide_empty' => false));
      ?>

      <label for="filter-region" class="screen-reader-text">지역 선택</label>
      <select id="filter-region" name="region">
        <option value="">지역 전체</option>
        <?php
        if (!is_wp_error($regions) && $regions) {
          foreach ($regions as $t) {
            printf(
              '<option value="%s"%s>%s</option>',
              esc_attr($t->slug),
              selected($cur_r, $t->slug, false),
              esc_html($t->name)
            );
          }
        }
        ?>
      </select>

      <label for="filter-theme" class="screen-reader-text">테마 선택</label>
      <select id="filter-theme" name="camp_theme">
        <option value="">테마 전체</option>
        <?php
        if (!is_wp_error($themes) && $themes) {
          foreach ($themes as $t) {
            printf(
              '<option value="%s"%s>%s</option>',
              esc_attr($t->slug),
              selected($cur_t, $t->slug, false),
              esc_html($t->name)
            );
          }
        }
        ?>
      </select>

      <div class="filter-actions">
        <button type="submit" class="ast-button btn-primary">필터 적용</button>
        <?php if ($cur_r || $cur_t) : ?>
          <a href="<?php echo esc_url(get_post_type_archive_link('campground')); ?>"
            class="btn-ghost" role="button" aria-label="필터 초기화">초기화</a>
        <?php endif; ?>
      </div>
    </form>
  </header>

  <?php if (have_posts()) : ?>
    <div class="ast-row">
      <?php while (have_posts()) : the_post(); ?>
        <?php
        $r_terms = get_the_terms(get_the_ID(), 'region');
        $t_terms = get_the_terms(get_the_ID(), 'camp_theme');
        ?>
        <article id="post-<?php the_ID(); ?>" <?php post_class('camp-item no-thumb'); ?>>

          <div class="camp-item__body">
            <header class="entry-header">
              <h2 class="entry-title" style="margin:0;">
                <a href="<?php the_permalink(); ?>"><?php the_title(); ?></a>
              </h2>

              <!-- 메타/요약은 목록에서 미표시 -->

              <!-- 태그 뱃지(지역/테마) -->
              <div class="card-badges">
                <?php
                if ($r_terms && !is_wp_error($r_terms)) {
                  foreach ($r_terms as $t) echo '<span class="badge">' . esc_html($t->name) . '</span>';
                }
                if ($t_terms && !is_wp_error($t_terms)) {
                  foreach ($t_terms as $t) echo '<span class="badge theme">' . esc_html($t->name) . '</span>';
                }
                ?>
              </div>
            </header>
          </div>

        </article>
      <?php endwhile; ?>
    </div>

    <?php the_posts_pagination(array(
      'mid_size' => 1,
      'prev_text' => '이전',
      'next_text' => '다음',
      'screen_reader_text' => '페이지 네비게이션',
    )); ?>

  <?php else : ?>
    <p>표시할 캠핑장이 없습니다.</p>
  <?php endif; ?>

</main>

<?php get_footer();
