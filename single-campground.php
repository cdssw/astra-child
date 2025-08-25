<?php

/**
 * Single template for Campground (미니멀: 본문 + 인근 캠핑장)
 */
get_header();

$id = get_the_ID();
// 현재 글의 지역(term)만 받아 인근 캠핑장 추천에 사용
$r_terms = get_the_terms($id, 'region');
?>
<main id="primary" class="site-main single-campground">

  <?php while (have_posts()) : the_post(); ?>
    <article id="post-<?php the_ID(); ?>" <?php post_class(); ?>>

      <script>
        // 캠핑 목록에서 넘어온 경우, 필터/페이지가 유지되도록 '뒤로가기'를 우선 사용
        (function() {
          var back = document.querySelectorAll('.btn-back');
          if (!back.length) return;
          var ref = document.referrer || '';
          if (ref && ref.indexOf(location.origin) === 0 && /\/camping(\/|\?)/.test(ref)) {
            back.forEach(function(a) {
              a.href = ref;
              a.dataset.from = 'referrer';
            });
          }
        })();
      </script>

      <!-- 테마 기본 제목/헤더는 출력하지 않습니다. (본문에 넣은 HTML이 제목/요약을 담당) -->
      <div class="entry-content">
        <?php the_content(); ?>
      </div>

      <!-- 인근 캠핑장 -->
      <footer class="entry-footer">
        <h3 class="section-title">인근 캠핑장</h3>
        <?php
        $args = array(
          'post_type'      => 'campground',
          'posts_per_page' => 6,
          'post__not_in'   => array($id),
          'tax_query'      => array(
            array(
              'taxonomy' => 'region',
              'field'    => 'term_id',
              'terms'    => $r_terms ? wp_list_pluck($r_terms, 'term_id') : array()
            )
          )
        );
        $rel = new WP_Query($args);
        if ($rel->have_posts()):
          echo '<div class="related-camps">';
          while ($rel->have_posts()): $rel->the_post();
            echo '<a class="related-card" href="' . esc_url(get_permalink()) . '">';
            echo '<div class="title">' . esc_html(get_the_title()) . '</div>';
            // 상세와 동일한 뱃지룩을 쓰고 싶으면 아래 주석 해제
            // $rr = get_the_terms(get_the_ID(), 'region');
            // $rt = get_the_terms(get_the_ID(), 'camp_theme');
            // echo '<div class="badges">';
            // if ($rr && !is_wp_error($rr)) foreach ($rr as $t) echo '<span class="badge">'.esc_html($t->name).'</span>';
            // if ($rt && !is_wp_error($rt)) foreach ($rt as $t) echo '<span class="badge theme">'.esc_html($t->name).'</span>';
            // echo '</div>';
            echo '</a>';
          endwhile;
          echo '</div>';
          wp_reset_postdata();
        else:
          echo '<p class="muted">인근 캠핑장이 없습니다.</p>';
        endif;
        ?>
      </footer>
    </article>
  <?php endwhile; ?>

</main>
<?php get_footer();
