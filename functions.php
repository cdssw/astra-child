<?php
if (!defined('ABSPATH')) exit;

/**
 * Astra Child functions.php (안정 정리본 – fixed)
 * - custom.css: 표준 링크 로드(루트 상대경로, filemtime 버전)
 * - 캠핑장 CPT/택소노미
 * - 아카이브 필터(region, camp_theme)
 * - 본문 1단락 뒤 광고 플레이스홀더
 * - Campground JSON-LD
 * - 테마 전환 시 리라이트 갱신
 */

/* 테마 전환 시 리라이트 규칙 갱신 */
add_action('after_switch_theme', function () {
  flush_rewrite_rules();
});

/* 캠핑장 CPT + 지역/테마 택소노미 */
add_action('init', function () {
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
    'public'       => true,
    'has_archive'  => true,
    'rewrite'      => array('slug' => 'camping', 'with_front' => false),
    'menu_icon'    => 'dashicons-location-alt',
    'supports'     => array('title', 'editor', 'excerpt', 'custom-fields'), // thumbnail 제외
    'show_in_rest' => true,
  ));

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
    'show_in_rest' => true,
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

/* 스타일 로드: 표준 링크 방식(루트 상대경로), filemtime 버전 */
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

/* 필요 시: 메뉴 폴백 차단(메뉴 미지정 시 자동 페이지 목록 방지) */
// add_filter('wp_nav_menu_args', function ($args) {
//   $args['fallback_cb'] = '__return_false';
//   return $args;
// });

// 캠핑장 단일 페이지에서 Astra 기본 제목 숨기기
add_filter('astra_the_title_enabled', function ($enabled) {
  if (is_singular('campground')) return false;
  return $enabled;
});
