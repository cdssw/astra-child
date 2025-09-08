<?php
// 사용법:
// 1) 인자: wp eval-file /tmp/cg_clean_hero.php -- ids=1,2  또는  -- all
// 2) 환경: CG_IDS=1,2 wp eval-file ...  /  CG_ALL=1 wp eval-file ...

if (!defined('ABSPATH')) {
  echo "ABORT\n";
  return;
}

function cg_clean_inline_styles($html)
{
  if ($html === '' || $html === null) return $html;

  $doc = new DOMDocument();
  libxml_use_internal_errors(true);
  $doc->loadHTML('<?xml encoding="utf-8"?><div id="cg-root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
  libxml_clear_errors();

  $xp   = new DOMXPath($doc);
  $root = $xp->query('//*[@id="cg-root"]')->item(0);

  // figure.cg-hero 선택
  $figs = $xp->query('//figure[contains(concat(" ", normalize-space(@class), " "), " cg-hero ")]');
  foreach ($figs as $fig) {
    // figure style 제거
    if ($fig->hasAttribute('style')) $fig->removeAttribute('style');
    // 내부 img style 제거 + cg-hero-img 클래스 보장
    $imgs = $xp->query('.//img', $fig);
    foreach ($imgs as $img) {
      if ($img->hasAttribute('style')) $img->removeAttribute('style');
      $cls = $img->getAttribute('class');
      if (strpos(' ' . $cls . ' ', ' cg-hero-img ') === false) {
        $img->setAttribute('class', trim($cls . ' cg-hero-img'));
      }
    }
  }
  $out = $doc->saveHTML($root);
  return preg_replace('#^<div id="cg-root">(.*)</div>$#s', '$1', $out);
}

// 1) 인자 파싱
global $args;
$opt = [];
foreach ((array)$args as $a) {
  if (strpos($a, '=') !== false) {
    list($k, $v) = explode('=', $a, 2);
    $opt[trim($k)] = trim($v);
  } else {
    $opt[trim($a)] = true; // all 등 플래그
  }
}

// 2) 환경변수 파싱(인자 미전달 시 대체)
if (empty($opt['ids']) && empty($opt['all'])) {
  $env_ids = getenv('CG_IDS');
  $env_all = getenv('CG_ALL');
  if (!empty($env_ids)) $opt['ids'] = $env_ids;
  if (!empty($env_all)) $opt['all'] = true;
}

// 대상 수집
$ids = [];
if (!empty($opt['ids'])) {
  $ids = array_filter(array_map('intval', explode(',', $opt['ids'])));
} else {
  if (empty($opt['all'])) {
    echo "사용법: -- all  또는  -- ids=1,2  (또는 CG_ALL/CG_IDS 환경변수)\n";
    return;
  }
  $ids = get_posts(['post_type' => 'campground', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids']);
}

$cleaned = 0;
$unchanged = 0;
foreach ($ids as $pid) {
  $old = get_post_field('post_content', $pid);
  $new = cg_clean_inline_styles($old);
  if ($new !== $old) {
    $res = wp_update_post(['ID' => $pid, 'post_content' => $new], true);
    if (!is_wp_error($res)) $cleaned++;
  } else {
    $unchanged++;
  }
  usleep(60000);
}
echo "cleaned={$cleaned} unchanged={$unchanged}\n";
