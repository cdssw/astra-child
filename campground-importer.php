<?php

/**
 * Plugin Name: Campground Importer
 * Description: CSV/JSON → 캠핑장(CPT: campground) 업서트 + 상세 본문(cg-detail) 자동 생성
 * Version: 0.2.0
 */
if (!defined('ABSPATH')) exit;

/* ================ 유틸 ================ */
function cg_kv($row, $keys)
{
  foreach ((array)$keys as $k) {
    if ($k !== null && array_key_exists($k, $row) && $row[$k] !== '') return trim($row[$k]);
  }
  return '';
}
function cg_norm_list($s)
{
  $s = str_replace(['_', ' ,', ', '], ['/', ',', ','], (string)$s);
  $parts = array_filter(array_map('trim', explode(',', $s)));
  return implode(', ', array_unique($parts));
}
function cg_slugify_ko($name)
{
  $slug = sanitize_title($name);
  return ($slug === '' && $name !== '') ? md5($name) : $slug;
}

// 광역/도 표준화
function cg_norm_province($name)
{
  $n = trim((string)$name);
  $n = str_replace(array(' ', '특별시', '광역시도'), array('', '특별시', '광역시'), $n); // 불필요 공백 최소화
  $map = array(
    '서울' => '서울특별시',
    '서울시' => '서울특별시',
    '부산' => '부산광역시',
    '대구' => '대구광역시',
    '인천' => '인천광역시',
    '광주' => '광주광역시',
    '대전' => '대전광역시',
    '울산' => '울산광역시',
    '세종' => '세종특별자치시',
    '경기' => '경기도',
    '강원' => '강원도',
    '충북' => '충청북도',
    '충남' => '충청남도',
    '전북' => '전라북도',
    '전남' => '전라남도',
    '경북' => '경상북도',
    '경남' => '경상남도',
    '제주' => '제주특별자치도'
  );
  if (isset($map[$n])) return $map[$n];
  // 이미 '경상남도' 같은 표기면 그대로
  return $n;
}

function cg_ensure_region_terms_hier($do_raw, $si_raw)
{
  $do = cg_norm_province($do_raw ?: '');
  $si = trim((string)$si_raw);

  // 부모(도/광역시) term 보장
  $parent_id = 0;
  if ($do !== '') {
    $parent_slug = sanitize_title($do);
    $parent = get_term_by('slug', $parent_slug, 'region');
    if (!$parent) {
      $r = wp_insert_term($do, 'region', array('slug' => $parent_slug, 'parent' => 0));
      if (!is_wp_error($r)) $parent_id = (int)$r['term_id'];
    } else {
      $parent_id = (int)$parent->term_id;
    }
  }
  // 시·군·구가 비었으면 부모만 반환
  if ($si === '') return $parent_id;

  // 자식 slug는 “부모slug-자식slug”로 유일화(이름은 그대로 ‘고성군’ 등 유지)
  $child_base = sanitize_title($si);
  $child_slug = ($do !== '') ? (sanitize_title($do) . '-' . $child_base) : $child_base;

  // 우선 '부모slug-자식slug'로 조회
  $child = get_term_by('slug', $child_slug, 'region');
  if ($child) return (int)$child->term_id;

  // 기존에 '자식slug'만으로 만들어진 고아 term가 있는지 탐색(있으면 새 composite slug로 복제)
  $orphan = get_term_by('slug', $child_base, 'region');
  if ($orphan && (int)$orphan->parent !== $parent_id) {
    // 새 term 생성(부모-자식 구조)
    $r2 = wp_insert_term($si, 'region', array('slug' => $child_slug, 'parent' => $parent_id));
    if (!is_wp_error($r2)) return (int)$r2['term_id'];
  }

  // 정상 생성
  $r = wp_insert_term($si, 'region', array('slug' => $child_slug, 'parent' => $parent_id));
  if (!is_wp_error($r)) return (int)$r['term_id'];

  // 최후: 이름으로 재조회
  $t2 = get_term_by('name', $si, 'region');
  return $t2 ? (int)$t2->term_id : $parent_id;
}

function cg_make_summary_from_row(array $row)
{
  $features = cg_norm_list(cg_kv($row, ['부대시설', 'features']));
  $around   = cg_norm_list(cg_kv($row, ['주변이용가능시설', 'around']));
  $pet      = cg_kv($row, ['반려동물출입', 'pet']);
  $addr     = cg_kv($row, ['address', '주소']);
  $themes   = cg_norm_list(cg_kv($row, ['테마환경', 'camp_theme']));
  $name     = cg_kv($row, ['title', '야영장명', 'name']);
  $seed     = abs(crc32($name . '|' . $addr . '|' . $features . '|' . $themes)); // 결정적 시드

  $pool = [];

  // 자연/물
  if (strpos($features, '물놀이') !== false || strpos($around, '강/물놀이') !== false || strpos($themes, '여름물놀이') !== false) {
    $pool[] = [
      '숲과 물가가 가까워 여름 성수기에 특히 인기가 있습니다',
      '물가와 가깝고 그늘이 많아 여름철 가족 캠핑에 적합합니다'
    ];
  }
  // 계절 테마
  if ($themes) {
    if (strpos($themes, '봄꽃') !== false)     $pool[] = ['봄꽃 시즌의 풍경이 아름답습니다'];
    if (strpos($themes, '가을단풍') !== false) $pool[] = ['가을 단풍 시기에 산책로가 특히 좋습니다'];
    if (strpos($themes, '겨울눈꽃') !== false) $pool[] = ['겨울에는 눈꽃 풍경을 즐길 수 있습니다'];
  }
  // 편의 접근성
  if (strpos($features, '마트') !== false || strpos($features, '편의점') !== false) {
    $pool[] = [
      '마트/편의점 접근성이 좋아 초보 캠퍼도 편리합니다',
      '생활 편의시설과 가까워 긴 체류에도 부담이 적습니다'
    ];
  }
  // 기본 편의
  $flags = [];
  if (strpos($features, '전기') !== false) $flags[] = '전기';
  if (strpos($features, '온수') !== false) $flags[] = '온수';
  if (strpos($features, '무선인터넷') !== false) $flags[] = '와이파이';
  if ($flags) $pool[] = ['기본 편의시설(' . implode('·', $flags) . ')을 제공합니다'];

  // 반려동물
  if ($pet !== '') {
    if (strpos($pet, '불가') !== false)      $pool[] = ['반려동물 출입은 불가합니다'];
    elseif (strpos($pet, '소형') !== false)  $pool[] = ['반려동물(소형견) 동반이 가능합니다'];
    elseif (strpos($pet, '가능') !== false)  $pool[] = ['반려동물 동반이 가능합니다'];
  }

  if (!$pool && $addr) return $addr . ' 인근에 위치한 캠핑장입니다.';

  // 문장 후보 펼치기
  $cands = [];
  foreach ($pool as $arr) foreach ($arr as $s) $cands[] = $s;
  // 간단 셔플
  mt_srand($seed);
  for ($i = count($cands) - 1; $i > 0; $i--) {
    $j = mt_rand(0, $i);
    [$cands[$i], $cands[$j]] = [$cands[$j], $cands[$i]];
  }
  $picked = array_slice($cands, 0, min(2, count($cands)));
  return $picked ? implode('. ', $picked) . '.' : '';
}

/* 상세 본문(cg-detail) HTML 생성 */
function cg_build_detail_html_from_row(array $row)
{
  // 기본 필드
  $name = cg_kv($row, ['title', '야영장명', 'name']);
  $do   = cg_kv($row, ['do', '도']);
  $si = cg_kv($row, ['sigungu', '시군구']);
  $addr = cg_kv($row, ['address', '주소']);
  $lat  = cg_kv($row, ['latitude', '위도', 'lat']);
  $lng  = cg_kv($row, ['longitude', '경도', 'lng']);

  // 유형 카운트(0은 제외)
  $c = [
    '일반야영장'    => (int) cg_kv($row, ['주요시설 일반야영장']),
    '자동차야영장'  => (int) cg_kv($row, ['주요시설 자동차야영장']),
    '글램핑'       => (int) cg_kv($row, ['주요시설 글램핑']),
    '카라반'       => (int) cg_kv($row, ['주요시설 카라반']),
    '개인 카라반'  => (int) cg_kv($row, ['주요시설 개인 카라반']),
    '덤프스테이션' => (int) cg_kv($row, ['주요시설 덤프스테이션']),
  ];
  $badges = '';
  foreach ($c as $label => $num) {
    if ($num <= 0) continue;
    $cls = 'type-general';
    if ($label === '자동차야영장') $cls = 'type-auto';
    if ($label === '글램핑')      $cls = 'type-glamp';
    if (strpos($label, '카라반') !== false) $cls = 'type-caravan';
    if ($label === '덤프스테이션') $cls = 'theme';
    $num_html = ($label === '덤프스테이션') ? '' : ' <b>' . $num . '</b>';
    $badges .= '<span class="badge ' . $cls . '">' . esc_html($label) . $num_html . '</span>';
  }

  // 반려동물 배지
  $pet = cg_kv($row, ['반려동물출입', 'pet']);
  if ($pet !== '') {
    if (strpos($pet, '불가') !== false)      $badges .= '<span class="badge no">반려동물 불가</span>';
    elseif (strpos($pet, '소형') !== false)  $badges .= '<span class="badge ok">반려동물(소형견)</span>';
    else                                     $badges .= '<span class="badge ok">반려동물 동반</span>';
  }

  // 시설/주변 칩
  $feat    = cg_norm_list(cg_kv($row, ['부대시설', 'features']));
  $around  = cg_norm_list(cg_kv($row, ['주변이용가능시설', 'around']));
  $chips_feat = '';
  if ($feat) {
    foreach (array_filter(array_map('trim', explode(',', str_replace(' ', '', $feat)))) as $t) {
      $t = str_replace('/', '·', $t);
      $chips_feat .= '<span class="badge theme">' . esc_html($t) . '</span>';
    }
  }
  $chips_ard = '';
  if ($around) {
    foreach (array_filter(array_map('trim', explode(',', $around))) as $t) {
      $chips_ard .= '<span class="badge">' . esc_html($t) . '</span>';
    }
  }
  $chips_html = '';
  if ($chips_feat || $chips_ard) {
    $chips_html .= '<section class="chips">';
    if ($chips_feat) $chips_html .= '<h3 class="chips-title">시설</h3><div class="chip-line">' . $chips_feat . '</div>';
    if ($chips_ard)  $chips_html .= '<h3 class="chips-title">주변</h3><div class="chip-line">' . $chips_ard . '</div>';
    $chips_html .= '</section>';
  }

  // 사이트 표(0행 제외)
  $w1 = cg_kv($row, ['사이트 크기1 가로']);
  $h1 = cg_kv($row, ['사이트 크기1 세로']);
  $n1 = (int)cg_kv($row, ['사이트 크기1 수량']);
  $w2 = cg_kv($row, ['사이트 크기2 가로']);
  $h2 = cg_kv($row, ['사이트 크기2 세로']);
  $n2 = (int)cg_kv($row, ['사이트 크기2 수량']);
  $w3 = cg_kv($row, ['사이트 크기3 가로']);
  $h3 = cg_kv($row, ['사이트 크기3 세로']);
  $n3 = (int)cg_kv($row, ['사이트 크기3 수량']);
  $rows = '';
  if ($n1 > 0) $rows .= '<tr><td>사이트1</td><td>' . esc_html($w1) . '</td><td>' . esc_html($h1) . '</td><td>' . $n1 . '</td></tr>';
  if ($n2 > 0) $rows .= '<tr><td>사이트2</td><td>' . esc_html($w2) . '</td><td>' . esc_html($h2) . '</td><td>' . $n2 . '</td></tr>';
  if ($n3 > 0) $rows .= '<tr><td>사이트3</td><td>' . esc_html($w3) . '</td><td>' . esc_html($h3) . '</td><td>' . $n3 . '</td></tr>';
  $table = $rows ? '<section class="sites"><h2>사이트 구성</h2><div class="tbl-wrap"><table class="tbl"><thead><tr><th>유형</th><th>가로(m)</th><th>세로(m)</th><th>수량(면)</th></tr></thead><tbody>' . $rows . '</tbody></table></div></section>' : '';

  // 핵심 정보(조건부)
  $info_items = [];
  $info_items[] = '<li><span class="k">주소</span><span class="v">' . esc_html($addr) . '</span></li>';
  $fire   = cg_kv($row, ['화로대']);
  if ($fire)   $info_items[] = '<li><span class="k">화로대</span><span class="v">' . esc_html($fire) . '</span></li>';
  $permit = cg_kv($row, ['인허가일자']);
  if ($permit) $info_items[] = '<li><span class="k">인허가일</span><span class="v">' . esc_html($permit) . '</span></li>';
  // 안전설비 요약
  $safe_parts = [];
  $v = (int)cg_kv($row, ['소화기 개수']);
  if ($v > 0) $safe_parts[] = '소화기 ' . $v;
  $v = (int)cg_kv($row, ['방화수 개수']);
  if ($v > 0) $safe_parts[] = '방화수 ' . $v;
  $v = (int)cg_kv($row, ['방화사 개수']);
  if ($v > 0) $safe_parts[] = '방화사 ' . $v;
  $v = (int)cg_kv($row, ['화재감지기 개수']);
  if ($v > 0) $safe_parts[] = '감지기 ' . $v;
  if ($safe_parts) $info_items[] = '<li><span class="k">안전설비</span><span class="v">' . esc_html(implode(' · ', $safe_parts)) . '</span></li>';
  $info_html = implode('', $info_items);

  // 지도 링크(주소 우선, 없으면 좌표)
  $has_map = ($addr !== '') || ($lat !== '' && $lng !== '');
  $mapLink = $addr ? 'https://map.kakao.com/link/search/' . rawurlencode($addr)
    : (($lat && $lng) ? 'https://map.kakao.com/link/map/' . $lat . ',' . $lng : '#');

  // 표시 텍스트
  $region_label = trim(($do ?: '') . ' ' . ($si ?: ''));
  $locText = $region_label ? "<strong>" . esc_html($region_label) . "</strong> · " . esc_html($addr) : esc_html($addr);
  $summary = esc_html(cg_make_summary_from_row($row));

  // 이스케이프
  $name_e = esc_html($name);
  $addr_e = esc_html($addr);
  $lat_e  = esc_attr($lat);
  $lng_e  = esc_attr($lng);

  // 배지 줄의 우측 액션
  $badges_actions = $has_map
    ? '<div class="badges-actions"><a class="btn-ghost open-map" href="' . $mapLink . '" target="_self" rel="noopener">지도 크게 보기</a></div>'
    : '<div class="badges-actions"></div>';

  // 최종 HTML
  return <<<HTML
<section class="cg-detail" aria-label="캠핑장 상세">
  <header class="hd">
    <div class="hd-row">
      <h1 class="title">{$name_e}</h1>
      <a class="btn-ghost btn-back" href="/camping/">← 목록으로</a>
    </div>
    <div class="loc">{$locText}</div>
    <div class="badges">
      <div class="badges-list">{$badges}</div>
      {$badges_actions}
    </div>
  </header>

  <div class="cg-map-wrap">
    <div id="cg-map" class="cg-map" data-lat="{$lat_e}" data-lng="{$lng_e}" data-address="{$addr_e}"></div>
  </div>

  <div class="content"><p>{$summary}</p></div>

  {$chips_html}

  <section class="info">
    <h2>핵심 정보</h2>
    <ul class="spec">{$info_html}</ul>
  </section>

  {$table}
</section>
HTML;
}

/* 글 업서트(존재 시 갱신) */
function cg_create_or_update_campground(array $data)
{
  // 0) 고유키(source_id) 결정
  $source_id = $data['source_id'] ?? ($data['no'] ?? '');
  if ($source_id === '') {
    $source_id = md5(($data['title'] ?? '') . '|' . ($data['address'] ?? ''));
  }

  // 1) 본문/제목/상태
  $post_title = $data['title'] ?? '';
  $content    = $data['_content'] ?? ($data['content'] ?? '');
  $status     = $data['status'] ?? 'publish';

  // 2) 기존 글 조회(업서트)
  $existing = get_posts(array(
    'post_type'      => 'campground',
    'post_status'    => 'any',
    'meta_key'       => '_source_id',
    'meta_value'     => $source_id,
    'fields'         => 'ids',
    'posts_per_page' => 1,
    'no_found_rows'  => true
  ));
  $post_id = $existing ? (int) $existing[0] : 0;

  $postarr = array(
    'post_type'   => 'campground',
    'post_status' => $status,
    'post_title'  => $post_title ?: $source_id,
  );
  if ($content) $postarr['post_content'] = $content;

  if ($post_id) {
    $postarr['ID'] = $post_id;
    $post_id = wp_update_post($postarr, true);
    $created = false;
  } else {
    $post_id = wp_insert_post($postarr, true);
    $created = true;
  }
  if (is_wp_error($post_id)) return $post_id;

  // 3) 메타 저장
  update_post_meta($post_id, '_source_id', $source_id);
  foreach (['address', 'phone', 'price_min', 'open_season', 'lat', 'lng'] as $m) {
    if (!empty($data[$m])) update_post_meta($post_id, $m, $data[$m]);
  }

  // 지역(부모: 시도 / 자식: 시군구) 할당 + 메타 보관
  $do = $data['do'] ?? ($data['도'] ?? '');
  $si = $data['sigungu'] ?? ($data['시군구'] ?? '');
  if ($do !== '') update_post_meta($post_id, 'region_do', $do);
  if ($si !== '') update_post_meta($post_id, 'region_si', $si);

  $child_id = cg_ensure_region_terms_hier($do, $si);
  if ($child_id) {
    // 자식(시군구)만 연결; 시도는 계층으로 따라갑니다.
    wp_set_object_terms($post_id, array($child_id), 'region', false);
  }

  // 4) camp_theme(테마) 할당
  // --- camp_theme 세팅(테마환경 + 반려동물 + 주요시설 카운트 병합) ---
  $themes_merge = array();

  // A) CSV '테마환경' (쉼표 구분)
  $themes_raw = $data['camp_theme'] ?? ($data['테마환경'] ?? '');
  if ($themes_raw) {
    $arr = array_filter(array_map('trim', explode(',', str_replace(' ', '', $themes_raw))));
    $themes_merge = array_merge($themes_merge, $arr);
  }

  // B) 반려동물 출입 → '반려동물 동반' 자동 부여(불가이면 부여하지 않음)
  $pet_raw = $data['반려동물출입'] ?? ($data['pet'] ?? '');
  if ($pet_raw) {
    $p = str_replace(' ', '', $pet_raw);
    if (mb_strpos($p, '불가') === false) { // '가능' 또는 '가능(소형견)'
      $themes_merge[] = '반려동물 동반';
    }
  }

  // C) 주요시설 카운트로 유형 자동 반영
  $cnt_gen  = (int)($data['주요시설 일반야영장']   ?? 0);
  $cnt_auto = (int)($data['주요시설 자동차야영장'] ?? 0);
  $cnt_gl   = (int)($data['주요시설 글램핑']       ?? 0);
  $cnt_cv   = (int)($data['주요시설 카라반']       ?? 0);
  $cnt_pcv  = (int)($data['주요시설 개인 카라반']  ?? 0);

  if ($cnt_gl  > 0) $themes_merge[] = '글램핑';
  if ($cnt_cv  > 0 || $cnt_pcv > 0) $themes_merge[] = '카라반';
  if ($cnt_auto > 0) $themes_merge[] = '오토캠핑';   // 드롭다운 라벨과 통일(원하시면 '자동차야영장'으로 변경)
  if ($cnt_gen > 0) $themes_merge[] = '일반야영장';

  // D) 정리 후 실제 term 생성/할당(덮어쓰기)
  $themes_merge = array_values(array_unique(array_filter($themes_merge)));
  $ids = array();
  foreach ($themes_merge as $nm) {
    $slug = sanitize_title($nm); // 한글 슬러그 허용
    $t = get_term_by('slug', $slug, 'camp_theme');
    if (!$t) {
      $r = wp_insert_term($nm, 'camp_theme', array('slug' => $slug));
      if (!is_wp_error($r)) $ids[] = (int)$r['term_id'];
    } else {
      $ids[] = (int)$t->term_id;
    }
  }
  // 항상 최신 상태로 덮어쓰기(빈 배열이면 해제)
  wp_set_object_terms($post_id, $ids, 'camp_theme', false);

  return array('post_id' => $post_id, 'created' => $created);
}

/* REST: 단건 업서트 /wp-json/cg/v1/import */
add_action('rest_api_init', function () {
  register_rest_route('cg/v1', '/import', array(
    'methods' => 'POST',
    'permission_callback' => function () {
      return current_user_can('edit_posts');
    },
    'callback' => function (WP_REST_Request $req) {
      $payload = $req->get_json_params();
      if (!$payload) return new WP_Error('cg_no_json', 'JSON 본문이 필요합니다', array('status' => 400));
      // 본문 자동 생성(없으면)
      if (empty($payload['_content'])) {
        $payload['_content'] = cg_build_detail_html_from_row($payload);
      }
      $res = cg_create_or_update_campground($payload);
      return is_wp_error($res) ? $res : rest_ensure_response($res);
    }
  ));
});

/* WP-CLI: CSV 일괄 임포트 */
if (defined('WP_CLI') && WP_CLI) {
  class CG_Import_CSV_CLI
  {
    public function import_csv($args, $assoc)
    {
      $file  = $assoc['file'] ?? '';
      $delim = $assoc['delimiter'] ?? ',';
      if (!$file || !file_exists($file)) \WP_CLI::error('CSV 파일이 없습니다: --file=/path/to.csv');

      $fh = fopen($file, 'r');
      if (!$fh) \WP_CLI::error('CSV를 열 수 없습니다');
      $header = fgetcsv($fh, 0, $delim);
      if (!$header) \WP_CLI::error('CSV 헤더가 비어있습니다');

      $n = 0;
      $created = 0;
      $updated = 0;
      $skipped = 0;
      while (($row = fgetcsv($fh, 0, $delim)) !== false) {
        $n++;
        $assocRow = array();
        foreach ($header as $i => $key) {
          $assocRow[$key] = $row[$i] ?? '';
        }

        $payload = array(
          'source_id'   => cg_kv($assocRow, ['source_id', 'no']),
          'title'       => cg_kv($assocRow, ['title', '야영장명', 'name']),
          'do'          => cg_kv($assocRow, ['do', '도']),
          'sigungu'     => cg_kv($assocRow, ['sigungu', '시군구']),
          'address'     => cg_kv($assocRow, ['address', '주소']),
          'phone'       => cg_kv($assocRow, ['전화', 'phone']),
          'lat'         => cg_kv($assocRow, ['latitude', '위도', 'lat']),
          'lng'         => cg_kv($assocRow, ['longitude', '경도', 'lng']),
          'price_min'   => cg_kv($assocRow, ['price_min', '최소요금']),
          'open_season' => cg_kv($assocRow, ['open_season', '운영시즌']),
          '테마환경'       => cg_kv($assocRow, ['테마환경', 'camp_theme']),
          '부대시설'       => cg_kv($assocRow, ['부대시설', 'features']),
          '주변이용가능시설' => cg_kv($assocRow, ['주변이용가능시설', 'around']),
          '반려동물출입'     => cg_kv($assocRow, ['반려동물출입', 'pet']),
          '인허가일자'       => cg_kv($assocRow, ['인허가일자']),
          '주요시설 일반야영장' => cg_kv($assocRow, ['주요시설 일반야영장']),
          '주요시설 자동차야영장' => cg_kv($assocRow, ['주요시설 자동차야영장']),
          '주요시설 글램핑'     => cg_kv($assocRow, ['주요시설 글램핑']),
          '주요시설 카라반'     => cg_kv($assocRow, ['주요시설 카라반']),
          '주요시설 개인 카라반' => cg_kv($assocRow, ['주요시설 개인 카라반']),
          '사이트 크기1 가로' => cg_kv($assocRow, ['사이트 크기1 가로']),
          '사이트 크기2 가로' => cg_kv($assocRow, ['사이트 크기2 가로']),
          '사이트 크기3 가로' => cg_kv($assocRow, ['사이트 크기3 가로']),
          '사이트 크기1 세로' => cg_kv($assocRow, ['사이트 크기1 세로']),
          '사이트 크기2 세로' => cg_kv($assocRow, ['사이트 크기2 세로']),
          '사이트 크기3 세로' => cg_kv($assocRow, ['사이트 크기3 세로']),
          '사이트 크기1 수량' => cg_kv($assocRow, ['사이트 크기1 수량']),
          '사이트 크기2 수량' => cg_kv($assocRow, ['사이트 크기2 수량']),
          '사이트 크기3 수량' => cg_kv($assocRow, ['사이트 크기3 수량']),
          '소화기 개수'     => cg_kv($assocRow, ['소화기 개수']),
          '방화수 개수'     => cg_kv($assocRow, ['방화수 개수']),
          '방화사 개수'     => cg_kv($assocRow, ['방화사 개수']),
          '화재감지기 개수' => cg_kv($assocRow, ['화재감지기 개수']),
        );
        if ($payload['title'] === '') {
          $skipped++;
          continue;
        }

        $payload['_content'] = cg_build_detail_html_from_row($assocRow);

        $res = cg_create_or_update_campground($payload);
        if (is_wp_error($res)) {
          \WP_CLI::warning('실패: ' . $payload['title'] . ' - ' . $res->get_error_message());
          $skipped++;
          continue;
        }
        if (!empty($res['created'])) $created++;
        else $updated++;
        if (($n % 100) === 0) \WP_CLI::log("진행: {$n}건 (생성 {$created}, 갱신 {$updated}, 제외 {$skipped})");
      }
      fclose($fh);
      \WP_CLI::success("완료: 총 {$n}건 처리 (생성 {$created}, 갱신 {$updated}, 제외 {$skipped})");
    }
  }
  \WP_CLI::add_command('cg import-csv', array(new CG_Import_CSV_CLI(), 'import_csv'));
}
