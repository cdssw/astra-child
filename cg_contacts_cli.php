<?php

/**
 * MU-Plugin: cg-contacts CLI 등록
 * 사용: wp cg-contacts --ids=1,2,3  또는  wp cg-contacts --all
 */
if (!defined('ABSPATH')) {
  return;
}

if (defined('WP_CLI') && WP_CLI) {

  // 전화 링크 생성
  if (!function_exists('cg_tel_href')) {
    function cg_tel_href($s)
    {
      $d = preg_replace('/[^0-9+]/', '', (string)$s);
      return $d ? 'tel:' . $d : '';
    }
  }

  // 본문 HTML에 '핵심 정보' 섹션을 찾아 전화/홈페이지를 병합(없으면 생성)
  if (!function_exists('cg_merge_contacts_into_keyinfo')) {
    function cg_merge_contacts_into_keyinfo($html, $post_id)
    {
      $tel  = trim((string)get_post_meta($post_id, 'phone', true));
      $home = trim((string)get_post_meta($post_id, 'homepage', true));
      if ($tel === '' && $home === '') return $html;

      $doc = new DOMDocument();
      libxml_use_internal_errors(true);
      $doc->loadHTML('<?xml encoding="utf-8"?><div id="cg-root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
      libxml_clear_errors();
      $xp   = new DOMXPath($doc);
      $root = $xp->query('//*[@id="cg-root"]')->item(0);

      // '핵심 정보' 헤더 탐색
      $hdr = null;
      foreach (['//h2', '//h3'] as $q) {
        foreach ($xp->query($q) as $h) {
          if (mb_strpos(trim($h->textContent), '핵심 정보') !== false) {
            $hdr = $h;
            break 2;
          }
        }
      }
      // 헤더 뒤의 ul/dl 찾기
      $list = null;
      if ($hdr) {
        for ($n = $hdr->nextSibling; $n; $n = $n->nextSibling) {
          if ($n->nodeType === XML_ELEMENT_NODE && in_array(strtolower($n->nodeName), ['ul', 'dl'])) {
            $list = $n;
            break;
          }
          if ($n->nodeType === XML_ELEMENT_NODE) {
            $cand = $xp->query('.//ul|.//dl', $n);
            if ($cand->length) {
              $list = $cand->item(0);
              break;
            }
          }
          if ($n->nodeType === XML_ELEMENT_NODE && in_array(strtolower($n->nodeName), ['section', 'article'])) break;
        }
      }
      // 섹션/리스트가 없으면 생성
      if (!$hdr) {
        $sec = $doc->createElement('section');
        $sec->setAttribute('class', 'key-info');
        $h2  = $doc->createElement('h2', '핵심 정보');
        $h2->setAttribute('class', 'sec-title');
        $sec->appendChild($h2);
        $ul  = $doc->createElement('ul');
        $ul->setAttribute('class', 'spec');
        $ul->setAttribute('style', 'list-style:none;margin:0;padding:0;');
        $sec->appendChild($ul);
        if ($root->firstChild) $root->insertBefore($sec, $root->firstChild);
        else $root->appendChild($sec);
        $list = $ul;
        $hdr = $h2;
      } elseif (!$list) {
        $ul  = $doc->createElement('ul');
        $ul->setAttribute('class', 'spec');
        $ul->setAttribute('style', 'list-style:none;margin:0;padding:0;');
        if ($hdr->nextSibling) $hdr->parentNode->insertBefore($ul, $hdr->nextSibling);
        else $hdr->parentNode->appendChild($ul);
        $list = $ul;
      }

      // 이미 존재하면 중복 방지
      $fragHtml = $doc->saveHTML($root);
      $hasTel   = (bool)preg_match('#href="tel:#i', $fragHtml);
      $hasHome  = (bool)preg_match('#>홈페이지<|aria-label="홈페이지"#u', $fragHtml);

      $add_li = function ($k, $v_html) use ($doc, $list) {
        $li = $doc->createElement('li');
        $li->setAttribute('class', 'spec-item');
        $kEl = $doc->createElement('span', $k);
        $kEl->setAttribute('class', 'k');
        $v  = $doc->createElement('span');
        $v->setAttribute('class', 'v');
        $f  = $doc->createDocumentFragment();
        $f->appendXML($v_html);
        $v->appendChild($f);
        $li->appendChild($kEl);
        $li->appendChild($v);
        $list->appendChild($li);
      };

      if ($tel && !$hasTel) {
        $href = esc_attr(cg_tel_href($tel));
        $add_li('전화', '<a href="' . $href . '">' . esc_html($tel) . '</a>');
      }
      if ($home && !$hasHome) {
        $url = esc_url($home);
        $add_li('홈페이지', '<a href="' . $url . '" target="_blank" rel="noopener">바로가기</a>');
      }

      $out = $doc->saveHTML($root);
      return preg_replace('#^<div id="cg-root">(.*)</div>$#s', '$1', $out);
    }
  }

  // CLI 커맨드 구현: wp cg-contacts --ids=1,2 또는 --all
  class CG_Contacts_CLI
  {
    public function __invoke($args, $assoc_args)
    {
      if (empty($assoc_args['ids']) && empty($assoc_args['all'])) {
        WP_CLI::error('사용법: --all 또는 --ids=1,2');
      }
      $ids = [];
      if (!empty($assoc_args['ids'])) {
        $ids = array_filter(array_map('intval', explode(',', $assoc_args['ids'])));
      } else {
        $ids = get_posts(['post_type' => 'campground', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids']);
      }

      $n = 0;
      $skip = 0;
      foreach ($ids as $pid) {
        $old = get_post_field('post_content', $pid);
        $new = cg_merge_contacts_into_keyinfo($old, $pid);
        if ($new !== $old) {
          $res = wp_update_post(['ID' => $pid, 'post_content' => $new], true);
          if (!is_wp_error($res)) $n++;
        } else {
          $skip++;
        }
        usleep(60000); // 과도한 쓰기 방지
      }
      WP_CLI::success("연락처 병합 완료: 업데이트 {$n}건, 변경 없음 {$skip}건");
    }
  }

  // 충돌 없이 독립 명령으로 등록
  WP_CLI::add_command('cg-contacts', 'CG_Contacts_CLI');
}
