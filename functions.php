<?php
if (!defined('ABSPATH')) exit;

/**
 * Astra Child functions.php (최종본)
 * - custom.css: 가장 마지막에 로드 + 동일 내용 인라인 주입(우선순위 보장)
 * - 캠핑장 CPT/택소노미 등록
 * - 아카이브 필터(region, camp_theme)
 * - 본문 1단락 뒤 광고 자리(플레이스홀더)
 * - Campground JSON-LD 출력
 * - 리라이트 갱신
 */

/* 테마 전환 시 리라이트 규칙 갱신 */
add_action('after_switch_theme', function () {
  flush_rewrite_rules();
});

/* 캠핑장 CPT + 지역/테마 택소노미 */
add_action('init', function () {
  // CPT: campground (대표이미지 없이 사용)
  register_post_type('campground', array(
    'labels' => array(
      'name'               => '캠핑장',
      'singular_name'      => '캠핑장',
      'add_new'            => '새 캠핑장 추가',
      'add_new_item'       => '캠핑장 추가',
      'edit_item'          => '캠핑장 편집',
      'new_item'           => '새 캠핑장',
      'view_item'          => '캠핑장 보기',
      'search_items'       => '캠핑장 검색',
      'not_found'          => '캠핑장을 찾을 수 없음',
      'not_found_in_trash' => '휴지통에 캠핑장이 없음',
      'all_items'          => '모든 캠핑장',
    ),
    'public'             => true,
    'has_archive'        => true,
    'rewrite'            => array('slug' => 'camping', 'with_front' => false),
    'menu_icon'          => 'dashicons-location-alt',
    'supports'           => array('title', 'editor', 'excerpt', 'custom-fields'), // thumbnail 제외
    'show_in_rest'       => true,
  ));

  // 지역(계층형)
  register_taxonomy('region', 'campground', array(
    'labels' => array(
      'name'          => '지역',
      'singular_name' => '지역',
      'search_items'  => '지역 검색',
      'all_items'     => '모든 지역',
      'edit_item'     => '지역 편집',
      'update_item'   => '지역 업데이트',
      'add_new_item'  => '지역 추가',
      'new_item_name' => '새 지역',
    ),
    'hierarchical' => true,
    'public'       => true,
    'rewrite'      => array('slug' => 'region', 'with_front' => false),
    'show_in_rest' => true,
  ));

  // 테마(비계층)
  register_taxonomy('camp_theme', 'campground', array(
    'labels' => array(
      'name'          => '캠핑 테마',
      'singular_name' => '캠핑 테마',
      'search_items'  => '테마 검색',
      'all_items'     => '모든 테마',
      'edit_item'     => '테마 편집',
      'update_item'   => '테마 업데이트',
      'add_new_item'  => '테마 추가',
      'new_item_name' => '새 테마',
    ),
    'hierarchical' => false,
    'public'       => true,
    'rewrite'      => array('slug' => 'camp-theme', 'with_front' => false),
    'show_in_rest'  => true,
  ));
});

/* 캠핑장 아카이브 필터링: /camping/?region=slug&camp_theme=slug */
add_action('pre_get_posts', function ($q) {
  if (is_admin() || !$q->is_main_query()) return;
  if (!$q->is_post_type_archive('campground')) return;

  $tax_query = array('relation' => 'AND');

  if (!empty($_GET['region'])) {
    $tax_query[] = array(
      'taxonomy' => 'region',
      'field'    => 'slug',
      'terms'    => sanitize_text_field($_GET['region']),
    );
  }
  if (!empty($_GET['camp_theme'])) {
    $tax_query[] = array(
      'taxonomy' => 'camp_theme',
      'field'    => 'slug',
      'terms'    => sanitize_text_field($_GET['camp_theme']),
    );
  }

  if (count($tax_query) > 1) {
    $q->set('tax_query', $tax_query);
  }

  $q->set('posts_per_page', 12);
});

/* 본문 첫 단락 뒤 광고(플레이스홀더) 자동 삽입: post, campground */
add_filter('the_content', function ($content) {
  if (is_admin()) return $content;
  if (!is_singular(array('post', 'campground'))) return $content;

  $placeholder = '<div class="ad-slot">광고 자리(본문 1단락 뒤)</div>';
  $p = strpos($content, '</p>');
  if ($p !== false) {
    return substr($content, 0, $p + 4) . $placeholder . substr($content, $p + 4);
  }
  return $content . $placeholder;
}, 20);

/* 단일 캠핑장 JSON-LD 출력 */
add_action('wp_head', function () {
  if (!is_singular('campground')) return;

  $id    = get_the_ID();
  $name  = get_the_title($id);
  $addr  = get_post_meta($id, 'address', true);
  $tel   = get_post_meta($id, 'phone', true);
  $lat   = get_post_meta($id, 'lat', true);
  $lng   = get_post_meta($id, 'lng', true);
  $price = get_post_meta($id, 'price_min', true);

  $data = array(
    '@context' => 'https://schema.org',
    '@type'    => 'Campground',
    'name'     => $name,
    'url'      => get_permalink($id),
  );

  if ($addr)  $data['address']     = $addr;
  if ($tel)   $data['telephone']   = $tel;
  if ($price) $data['priceRange']  = '₩' . $price . '+';
  if ($lat && $lng) {
    $data['geo'] = array('@type' => 'GeoCoordinates', 'latitude' => $lat, 'longitude' => $lng);
  }

  echo '<script type="application/ld+json">' .
    wp_json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) .
    '</script>';
}, 30);

/* 스타일: 가장 마지막에 로드 + 같은 내용을 인라인으로 한 번 더 주입(우선순위 보장) */
add_action('wp_enqueue_scripts', function () {
  // 부모 Astra 핸들이 등록되어 있으면 의존성으로 연결
  $deps = array();
  if (wp_style_is('astra-theme-css', 'enqueued') || wp_style_is('astra-theme-css', 'registered')) {
    $deps[] = 'astra-theme-css';
  }

  // 자식 style.css (루트 상대경로)
  $child_path = WP_CONTENT_DIR . '/themes/astra-child/style.css';
  $child_uri  = '/wp-content/themes/astra-child/style.css';
  if (file_exists($child_path)) {
    wp_enqueue_style(
      'astra-child-style',
      $child_uri,
      $deps,
      filemtime($child_path)
    );
  }

  // custom.css (루트 상대경로)
  $custom_path = WP_CONTENT_DIR . '/themes/astra-child/assets/css/custom.css';
  $custom_uri  = '/wp-content/themes/astra-child/assets/css/custom.css';
  if (file_exists($custom_path)) {
    wp_enqueue_style(
      'astra-child-custom',
      $custom_uri,
      array('astra-child-style'),
      filemtime($custom_path)
    );

    // 같은 내용을 인라인으로도 한 번 더 주입 → 어떤 인라인/동적 CSS보다 항상 뒤에서 적용
    $css = file_get_contents($custom_path);
    if ($css) {
      wp_add_inline_style('astra-child-custom', $css);
    }
  }
}, 9999);

/* 필요 시: 네비게이션 메뉴 폴백(자동 페이지 목록) 차단 – 메뉴 미지정시 빈 상태 유지 */
// add_filter('wp_nav_menu_args', function ($args) {
//   $args['fallback_cb'] = '__return_false';
//   return $args;
// });

/* 끝 */
