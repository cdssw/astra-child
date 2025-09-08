<?php
/*
Plugin Name: CG Repair Media
Description: Download from _image_original, set featured image, and normalize hero in one command.
Version: 0.1
*/
if (!defined('ABSPATH')) exit;

if (!class_exists('CGX_Repair_Media')) {
  class CGX_Repair_Media
  {
    public static function is_abs_url($u)
    {
      return is_string($u) && preg_match('#^https?://#i', $u);
    }

    // _image_original이 상대경로면 도메인 보정
    public static function ensure_abs_meta($post_id)
    {
      $u = trim((string)get_post_meta($post_id, '_image_original', true));
      if ($u && !self::is_abs_url($u) && strpos($u, 'upload/camp') !== false) {
        $nu = 'https://gocamping.or.kr/' . ltrim($u, '/');
        update_post_meta($post_id, '_image_original', $nu);
        return $nu;
      }
      return $u;
    }

    // Referer 포함 다운로드(안티 핫링크 회피)
    public static function download_with_referer($url)
    {
      $tmp = wp_tempnam($url);
      if (!$tmp) return new WP_Error('tmp_fail', 'tmp file failed');
      $res = wp_remote_get($url, [
        'timeout' => 25,
        'stream' => true,
        'filename' => $tmp,
        'headers' => ['Referer' => 'https://gocamping.or.kr/'],
      ]);
      if (is_wp_error($res)) {
        @unlink($tmp);
        return $res;
      }
      $code = wp_remote_retrieve_response_code($res);
      if ($code !== 200) {
        @unlink($tmp);
        return new WP_Error('http_' . $code, 'download failed');
      }
      return $tmp;
    }

    public static function attachment_ok($aid)
    {
      if (!$aid) return false;
      $p = get_attached_file($aid);
      return ($p && file_exists($p) && filesize($p) > 0);
    }

    // 외부 URL을 내려받아 대표 이미지로 설정(필요 시 강제 덮어쓰기)
    public static function set_featured_from_url($post_id, $url, $force = false)
    {
      if (has_post_thumbnail($post_id) && !$force && self::attachment_ok(get_post_meta($post_id, '_thumbnail_id', true))) {
        return true;
      }
      if (!self::is_abs_url($url)) return false;

      // 미디어 유틸 포함
      require_once ABSPATH . 'wp-admin/includes/file.php';
      require_once ABSPATH . 'wp-admin/includes/media.php';
      require_once ABSPATH . 'wp-admin/includes/image.php';

      $tmp = self::download_with_referer($url);
      if (is_wp_error($tmp)) return false;

      $file_array = [
        'name'     => basename(parse_url($url, PHP_URL_PATH)) ?: 'image.jpg',
        'tmp_name' => $tmp,
      ];
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

    // 히어로 HTML(large → medium_large → full 폴백)
    public static function build_hero_html($post_id)
    {
      if (!has_post_thumbnail($post_id)) return '';
      $aid = get_post_thumbnail_id($post_id);
      $src = wp_get_attachment_image_url($aid, 'large');
      if (!$src) $src = wp_get_attachment_image_url($aid, 'medium_large');
      if (!$src) $src = wp_get_attachment_image_url($aid, 'full');
      if (!$src) return '';
      $alt = esc_attr(get_the_title($post_id));
      return '<figure class="cg-hero" style="margin:0 0 16px 0;"><img src="' . esc_url($src) . '" alt="' . $alt . '" loading="lazy" decoding="async" style="width:100%;height:auto;border-radius:8px;"></figure>';
    }

    // 본문 정리: 이전 히어로 제거 → 맨 위가 이미지/figure로 시작하지 않으면 히어로 1장 프리펜드
    public static function normalize_post($post_id)
    {
      $html = get_post_field('post_content', $post_id);
      $removed = 0;
      $html = preg_replace('#<figure[^>]*class="[^"]*cg-hero[^"]*"[^>]*>.*?</figure>#is', '', $html, -1, $removed);
      $starts = (bool)preg_match('#^\s*<(figure|img)\b#i', ltrim($html));
      $prepend = '';
      if (!$starts) {
        $hero = self::build_hero_html($post_id);
        if ($hero) $prepend = $hero . "\n\n";
      }
      if (!$removed && $prepend === '') return false;
      $new = $prepend . $html;
      $res = wp_update_post(['ID' => $post_id, 'post_content' => $new], true);
      return !is_wp_error($res);
    }
  }
}

if (defined('WP_CLI') && WP_CLI && class_exists('CGX_Repair_Media')) {
  // 한 방에: 원본 보정 → 다운로드/대표이미지 → 본문 히어로 정리
  WP_CLI::add_command('cg repair-media', function ($args, $assoc) {
    $force   = !empty($assoc['force']);
    $do_norm = empty($assoc['no-normalize']);
    $batch   = isset($assoc['batch-size']) ? max(10, (int)$assoc['batch-size']) : 200;

    if (!empty($assoc['ids'])) {
      $ids = array_map('intval', explode(',', $assoc['ids']));
    } else {
      if (empty($assoc['all'])) WP_CLI::error('사용법: --all 또는 --ids=1,2');
      $ids = get_posts(['post_type' => 'campground', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids']);
    }

    $done_dl = 0;
    $done_norm = 0;
    $skip = 0;
    $fail = 0;
    $chunks = array_chunk($ids, $batch);

    foreach ($chunks as $chunk) {
      foreach ($chunk as $pid) {
        $url  = CGX_Repair_Media::ensure_abs_meta($pid);
        $need = $force ? true : !CGX_Repair_Media::attachment_ok(get_post_meta($pid, '_thumbnail_id', true));

        $ok = true;
        if ($need) {
          if ($url && CGX_Repair_Media::is_abs_url($url)) {
            $ok = CGX_Repair_Media::set_featured_from_url($pid, $url, $force);
            if ($ok) $done_dl++;
            else $fail++;
          } else {
            $skip++; // 소스 URL 없음/부적합
          }
        }
        if ($do_norm && CGX_Repair_Media::normalize_post($pid)) $done_norm++;
      }
      usleep(150000);
    }

    WP_CLI::success(sprintf('다운로드 %d, 본문정리 %d, 건너뜀 %d, 실패 %d', $done_dl, $done_norm, $skip, $fail));
  });
}
