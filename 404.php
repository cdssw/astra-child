<?php

/**
 * 404 Template (Astra Child)
 */
get_header(); ?>

<main id="primary" class="site-main ast-container">
  <section class="notfound-wrap" aria-label="페이지를 찾을 수 없음">
    <div class="notfound-icon" aria-hidden="true">😕</div>
    <h1 class="notfound-title">이 페이지는 존재하지 않는 것 같습니다.</h1>
    <p class="notfound-desc">주소가 바뀌었거나, 잘못된 링크일 수 있어요. 아래 버튼으로 이동해 보세요.</p>

    <div class="notfound-actions">
      <a class="btn-primary" href="<?php echo esc_url(home_url('/')); ?>">홈으로</a>
      <a class="btn-ghost" href="<?php echo esc_url(get_post_type_archive_link('campground')); ?>">캠핑장 목록으로</a>
    </div>

    <form class="notfound-search" role="search" method="get" action="<?php echo esc_url(home_url('/')); ?>">
      <label for="nf-s" class="screen-reader-text">사이트 검색</label>
      <input id="nf-s" type="search" name="s" placeholder="검색…">
      <button type="submit" aria-label="검색">🔍</button>
    </form>
  </section>
</main>

<?php get_footer();
