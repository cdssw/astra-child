<?php
add_action('wp_enqueue_scripts', function () {
  // 부모 → 자식 → 커스텀 순서
  wp_enqueue_style('astra-child-style', get_stylesheet_uri(), array('astra-theme-css'), '1.0.0');

  $path = get_stylesheet_directory() . '/assets/css/custom.css';
  $uri  = get_stylesheet_directory_uri() . '/assets/css/custom.css';
  if (file_exists($path)) {
    wp_enqueue_style('astra-child-custom', $uri, array('astra-child-style'), filemtime($path));
  }
}, 20);

// 캠핑장 CPT + 지역/테마 택소노미
add_action('init', function () {
  // CPT: campground
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
      'all_items'          => '모든 캠핑장'
    ),
    'public'             => true,
    'has_archive'        => true,
    'rewrite'            => array('slug' => 'camping', 'with_front' => false),
    'menu_icon'          => 'dashicons-location-alt',
    'supports'           => array('title','editor','thumbnail','excerpt','custom-fields'),
    'show_in_rest'       => true
  ));

  // 지역(계층형) taxonomy: region
  register_taxonomy('region', 'campground', array(
    'labels' => array(
      'name'          => '지역',
      'singular_name' => '지역',
      'search_items'  => '지역 검색',
      'all_items'     => '모든 지역',
      'edit_item'     => '지역 편집',
      'update_item'   => '지역 업데이트',
      'add_new_item'  => '지역 추가',
      'new_item_name' => '새 지역'
    ),
    'hierarchical'      => true,
    'public'            => true,
    'rewrite'           => array('slug' => 'region', 'with_front' => false),
    'show_in_rest'      => true
  ));

  // 테마(비계층) taxonomy: camp_theme
  register_taxonomy('camp_theme', 'campground', array(
    'labels' => array(
      'name'          => '캠핑 테마',
      'singular_name' => '캠핑 테마',
      'search_items'  => '테마 검색',
      'all_items'     => '모든 테마',
      'edit_item'     => '테마 편집',
      'update_item'   => '테마 업데이트',
      'add_new_item'  => '테마 추가',
      'new_item_name' => '새 테마'
    ),
    'hierarchical'      => false,
    'public'            => true,
    'rewrite'           => array('slug' => 'camp-theme', 'with_front' => false),
    'show_in_rest'      => true
  ));
});

// 테마 활성화 시 리라이트 갱신
add_action('after_switch_theme', function () { flush_rewrite_rules(); });

// 캠핑장 아카이브 필터링: /camping/?region=slug&camp_theme=slug
add_action('pre_get_posts', function ($q) {
  if (is_admin() || !$q->is_main_query()) return;
  if (!$q->is_post_type_archive('campground')) return;

  $tax_query = array('relation' => 'AND');

  if (!empty($_GET['region'])) {
    $tax_query[] = array(
      'taxonomy' => 'region',
      'field'    => 'slug',
      'terms'    => sanitize_text_field($_GET['region'])
    );
  }
  if (!empty($_GET['camp_theme'])) {
    $tax_query[] = array(
      'taxonomy' => 'camp_theme',
      'field'    => 'slug',
      'terms'    => sanitize_text_field($_GET['camp_theme'])
    );
  }

  if (count($tax_query) > 1) {
    $q->set('tax_query', $tax_query);
  }

  // 페이지당 개수
  $q->set('posts_per_page', 12);
});

// 본문 첫 단락 뒤 광고 플레이스홀더 삽입 (post, campground)
add_filter('the_content', function ($content) {
  if (!is_singular(array('post','campground')) || is_admin()) return $content;

  $placeholder = '<div class="ad-slot">광고 자리(본문 1단락 뒤)</div>';
  $p = strpos($content, '</p>');
  if ($p !== false) {
    return substr($content, 0, $p+4) . $placeholder . substr($content, $p+4);
  }
  return $content . $placeholder;
}, 20);

// 단일 캠핑장 JSON-LD 출력
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

  if ($addr) $data['address'] = $addr;
  if ($tel)  $data['telephone'] = $tel;
  if ($price) $data['priceRange'] = '₩' . $price . '+';
  if ($lat && $lng) {
    $data['geo'] = array('@type'=>'GeoCoordinates','latitude'=>$lat,'longitude'=>$lng);
  }

  echo '<script type="application/ld+json">'.wp_json_encode($data, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).'</script>';
}, 30);