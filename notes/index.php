<?php
declare(strict_types=1);
require_once __DIR__ . '/lib.php';
require_login();

$meId = (int)(current_user()['id'] ?? 0);
$pdo  = get_pdo();

if (is_post()) {
    $isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH'])
        && strtolower((string)$_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

    $errorMessage = '';
    $successPayload = null;

    if (!verify_csrf_token($_POST[CSRF_TOKEN_NAME] ?? null)) {
        $errorMessage = 'Invalid security token.';
    } elseif (isset($_POST['update_note_shares'])) {
        $noteId = (int)($_POST['note_id'] ?? 0);
        $selected = array_map('intval', (array)($_POST['shared_ids'] ?? []));
        $note = notes_fetch($noteId);
        if (!$note || !notes_can_share($note)) {
            $errorMessage = 'You cannot change sharing for this note.';
        } else {
            try {
                $result = notes_apply_shares($noteId, $selected, $note, true);
                $shares = notes_get_share_details($noteId);
                $successPayload = [
                    'ok'       => true,
                    'shares'   => $shares,
                    'selected' => $result['after'],
                    'note_id'  => $noteId,
                ];
            } catch (Throwable $e) {
                error_log('index note share failed: ' . $e->getMessage());
                $errorMessage = 'Failed to update shares.';
            }
        }
    } elseif (isset($_POST['update_template_shares'])) {
        $templateId = (int)($_POST['template_id'] ?? 0);
        $selected   = array_map('intval', (array)($_POST['shared_ids'] ?? []));
        $template   = notes_template_fetch($templateId);
        if (!$template || !notes_template_can_share($template, $meId)) {
            $errorMessage = 'You cannot share this template.';
        } else {
            try {
                $result = notes_apply_template_shares($templateId, $selected, $template, true);
                $shares = notes_template_share_details($templateId);
                $successPayload = [
                    'ok'         => true,
                    'shares'     => $shares,
                    'selected'   => $result['after'],
                    'template_id'=> $templateId,
                ];
            } catch (Throwable $e) {
                error_log('index template share failed: ' . $e->getMessage());
                $errorMessage = 'Failed to update template shares.';
            }
        }
    } elseif (isset($_POST['quick_note'])) {
        $title    = trim((string)($_POST['title'] ?? ''));
        $noteDate = trim((string)($_POST['note_date'] ?? date('Y-m-d')));
        $status   = notes_normalize_status($_POST['status'] ?? NOTES_DEFAULT_STATUS);
        $icon     = trim((string)($_POST['icon'] ?? ''));
        $coverUrl = trim((string)($_POST['cover_url'] ?? ''));
        $body     = trim((string)($_POST['body'] ?? ''));
        $tagInput = trim((string)($_POST['quick_tags'] ?? ''));

        if ($title === '') {
            $errorMessage = 'Title is required to capture a note.';
        } elseif ($noteDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $noteDate)) {
            $errorMessage = 'Provide a valid date (YYYY-MM-DD).';
        } else {
            $tags = [];
            if ($tagInput !== '') {
                $parts = preg_split('/[,;\n]/', $tagInput) ?: [];
                foreach ($parts as $part) {
                    $label = trim((string)$part);
                    if ($label !== '') {
                        $tags[] = ['label' => $label];
                    }
                }
            }
            $normalizedTags = notes_normalize_tags_input($tags);

            $blocks = [];
            if ($body !== '') {
                $blocks[] = [
                    'uid'  => notes_generate_block_uid(),
                    'type' => 'paragraph',
                    'text' => $body,
                ];
            }

            try {
                $noteId = notes_insert([
                    'user_id'    => $meId,
                    'note_date'  => $noteDate,
                    'title'      => $title,
                    'body'       => $body,
                    'icon'       => $icon,
                    'cover_url'  => $coverUrl,
                    'status'     => $status,
                    'properties' => notes_default_properties(),
                    'tags'       => $normalizedTags,
                    'blocks'     => $blocks,
                ]);
                log_event('note.create.quick', 'note', $noteId);
                $payload = notes__hydrate_index_payload($noteId);
                if ($payload) {
                    $successPayload = [
                        'ok'      => true,
                        'note'    => $payload,
                    ];
                } else {
                    $errorMessage = 'Note created but could not refresh the workspace.';
                }
            } catch (Throwable $e) {
                error_log('quick note capture failed: ' . $e->getMessage());
                $errorMessage = 'Unable to capture note.';
            }
        }
    }

    if ($isAjax) {
        header('Content-Type: application/json');
        if ($errorMessage !== '') {
            http_response_code(400);
            echo json_encode(['ok' => false, 'message' => $errorMessage], JSON_UNESCAPED_UNICODE);
        } else {
            echo json_encode($successPayload ?? ['ok' => true], JSON_UNESCAPED_UNICODE);
        }
        exit;
    }

    if ($errorMessage !== '') {
        redirect_with_message('index.php', $errorMessage, 'error');
    } else {
        redirect_with_message('index.php', 'Sharing updated.', 'success');
    }
    exit;
}

if (!function_exists('notes__search_index')) {
    function notes__search_index(string $title, string $body = ''): string {
        $plain = trim($title . ' ' . preg_replace('/\s+/u', ' ', strip_tags($body)));
        if ($plain === '') {
            return '';
        }
        if (function_exists('mb_strtolower')) {
            return mb_strtolower($plain, 'UTF-8');
        }
        return strtolower($plain);
    }
}
if (!function_exists('notes__relative_time')) {
    function notes__relative_time(?string $timestamp): string {
        if (!$timestamp) {
            return '';
        }
        try {
            $dt = new DateTimeImmutable($timestamp);
        } catch (Throwable $e) {
            return (string)$timestamp;
        }
        $now  = new DateTimeImmutable('now');
        $diff = $now->getTimestamp() - $dt->getTimestamp();
        if ($diff < 0) {
            $diff = 0;
        }
        if ($diff < 60) {
            return $diff . 's ago';
        }
        $mins = (int)floor($diff / 60);
        if ($mins < 60) {
            return $mins . 'm ago';
        }
        $hours = (int)floor($mins / 60);
        if ($hours < 24) {
            return $hours . 'h ago';
        }
        $days = (int)floor($hours / 24);
        if ($days < 7) {
            return $days . 'd ago';
        }
        if ($days < 30) {
            return (int)floor($days / 7) . 'w ago';
        }
        return $dt->format('M j, Y');
    }
}
if (!function_exists('notes__excerpt')) {
    function notes__excerpt(string $body, int $limit = 180): string {
        $plain = trim(preg_replace('/\s+/u', ' ', strip_tags($body)) ?? '');
        if ($plain === '') {
            return '';
        }
        if (function_exists('mb_strimwidth')) {
            return (string)mb_strimwidth($plain, 0, $limit, '…', 'UTF-8');
        }
        return strlen($plain) > $limit ? substr($plain, 0, $limit - 1) . '…' : $plain;
    }
}
if (!function_exists('notes__format_date')) {
    function notes__format_date(?string $date): string {
        $date = trim((string)$date);
        if ($date === '') {
            return '';
        }
        try {
            return (new DateTimeImmutable($date))->format('M j, Y');
        } catch (Throwable $e) {
            return $date;
        }
    }
}

if (!function_exists('notes__hydrate_index_payload')) {
    function notes__hydrate_index_payload(int $noteId): ?array {
        $note = notes_fetch($noteId);
        if (!$note) {
            return null;
        }

        $meta = notes_fetch_page_meta($noteId);
        $properties = notes_normalize_properties($meta['properties'] ?? notes_default_properties());
        $status = notes_normalize_status($meta['status'] ?? NOTES_DEFAULT_STATUS);
        $icon = trim((string)($meta['icon'] ?? ''));
        $coverUrl = trim((string)($meta['cover_url'] ?? ''));

        $tags = notes_fetch_note_tags($noteId);
        $tagPayload = array_map(static function ($tag): array {
            return [
                'label' => (string)($tag['label'] ?? ''),
                'color' => (string)($tag['color'] ?? ''),
            ];
        }, is_array($tags) ? $tags : []);

        $shareDetails = notes_get_share_details($noteId);
        $shareIds = [];
        $shareLabels = [];
        foreach ($shareDetails as $share) {
            $sid = (int)($share['id'] ?? 0);
            if ($sid <= 0) {
                continue;
            }
            $shareIds[] = $sid;
            $label = trim((string)($share['label'] ?? ''));
            if ($label !== '') {
                $shareLabels[] = $label;
            }
        }

        $excerpt = notes__excerpt((string)($note['body'] ?? ''), 220);
        $photoCount = count(array_filter(notes_fetch_photos($noteId)));
        $commentCount = notes_comment_count($noteId);

        $timestamp = $note['updated_at'] ?? $note['created_at'] ?? $note['note_date'] ?? null;
        $updatedRelative = $timestamp ? notes__relative_time((string)$timestamp) : '';
        $updatedAbsolute = '';
        if ($timestamp) {
            try {
                $updatedAbsolute = (new DateTimeImmutable((string)$timestamp))->format('Y-m-d H:i');
            } catch (Throwable $e) {
                $updatedAbsolute = (string)$timestamp;
            }
        }

        $ownerId = (int)($note['user_id'] ?? 0);

        return [
            'id'             => $noteId,
            'title'          => trim((string)($note['title'] ?? '')) ?: 'Untitled',
            'status'         => $status,
            'statusLabel'    => notes_status_label($status),
            'statusBadge'    => notes_status_badge_class($status),
            'icon'           => $icon,
            'coverUrl'       => $coverUrl,
            'excerpt'        => $excerpt,
            'ownerId'        => $ownerId,
            'ownerLabel'     => notes_user_label($ownerId),
            'isOwner'        => $ownerId === (int)(current_user()['id'] ?? 0),
            'isShared'       => $shareIds && $ownerId !== (int)(current_user()['id'] ?? 0),
            'properties'     => $properties,
            'tags'           => $tagPayload,
            'shareIds'       => $shareIds,
            'shareLabels'    => $shareLabels,
            'shareCount'     => count($shareIds),
            'photoCount'     => $photoCount,
            'commentCount'   => $commentCount,
            'noteDate'       => $note['note_date'] ?? null,
            'updatedRelative'=> $updatedRelative,
            'updatedAbsolute'=> $updatedAbsolute,
            'canShare'       => notes_can_share($note),
            'links'          => [
                'view' => 'view.php?id=' . $noteId,
                'edit' => 'edit.php?id=' . $noteId,
            ],
        ];
    }
}

$hasNoteDate    = notes__col_exists($pdo, 'notes', 'note_date');
$hasCreatedAt   = notes__col_exists($pdo, 'notes', 'created_at');
$hasUpdatedAt   = notes__col_exists($pdo, 'notes', 'updated_at');
$hasPhotosTbl   = notes__table_exists($pdo, 'note_photos');
$hasCommentsTbl = notes__table_exists($pdo, 'note_comments');
$hasMetaTbl     = notes__table_exists($pdo, 'note_pages_meta');
$hasTagTbl      = notes__table_exists($pdo, 'note_tag_assignments') && notes__table_exists($pdo, 'note_tags_catalog');

$search      = trim((string)($_GET['q'] ?? ''));
$from        = trim((string)($_GET['from'] ?? ''));
$to          = trim((string)($_GET['to'] ?? ''));
$statusParam = trim((string)($_GET['status'] ?? ''));
$tagFilter   = trim((string)($_GET['tag'] ?? ''));

$statuses = notes_available_statuses();
$statusFilter = '';
if ($statusParam !== '') {
    $candidateKey = strtolower(str_replace([' ', '-'], '_', $statusParam));
    if (isset($statuses[$candidateKey])) {
        $statusFilter = $candidateKey;
    } else {
        foreach ($statuses as $slug => $label) {
            if (strcasecmp($label, $statusParam) === 0) {
                $statusFilter = $slug;
                break;
            }
        }
    }
}

$allowedViews = ['table', 'board'];
$viewInput = $_GET['view'] ?? '';
if ($viewInput === 'sticky') {
    $viewInput = 'board';
}

$today = date('Y-m-d');
if ($viewInput && in_array($viewInput, $allowedViews, true)) {
    $view = $viewInput;
} else {
    $cookieView = $_COOKIE['notes_view'] ?? '';
    if ($cookieView === 'sticky') {
        $cookieView = 'board';
    }
    if ($cookieView && in_array($cookieView, $allowedViews, true)) {
        $view = $cookieView;
    } else {
        $view = 'table';
    }
}
@setcookie('notes_view', $view, time() + 31536000, '/', '', false, true);

$where  = [];
$params = [];

if ($search !== '') {
    $where[] = '(n.title LIKE :q OR COALESCE(n.body, "") LIKE :q)';
    $params[':q'] = '%' . $search . '%';
}
if ($hasNoteDate && $from !== '') {
    $where[] = 'n.note_date >= :from';
    $params[':from'] = $from;
}
if ($hasNoteDate && $to !== '') {
    $where[] = 'n.note_date <= :to';
    $params[':to'] = $to;
}

$sharesCol = notes__shares_column($pdo);
if ($sharesCol) {
    $shareValue = $sharesCol === 'user_id' ? $meId : (string)$meId;
    $where[] = "(n.user_id = :me_owner_where OR EXISTS (SELECT 1 FROM notes_shares s WHERE s.note_id = n.id AND s.{$sharesCol} = :me_share_where))";
    $params[':me_owner_where'] = $meId;
    $params[':me_share_where'] = $shareValue;
    $isSharedExpr = "EXISTS(SELECT 1 FROM notes_shares s WHERE s.note_id = n.id AND s.{$sharesCol} = :me_share_select) AS is_shared";
    $params[':me_share_select'] = $shareValue;
} else {
    $where[] = 'n.user_id = :me_owner_where';
    $params[':me_owner_where'] = $meId;
    $isSharedExpr = '0 AS is_shared';
}

if ($statusFilter !== '' && $hasMetaTbl) {
    $where[] = 'COALESCE(npm.status, :default_status) = :status_filter';
    $params[':status_filter'] = $statusFilter;
    $params[':default_status'] = NOTES_DEFAULT_STATUS;
}

if ($tagFilter !== '' && $hasTagTbl) {
    $where[] = 'EXISTS (SELECT 1 FROM note_tag_assignments nta JOIN note_tags_catalog tc ON tc.id = nta.tag_id WHERE nta.note_id = n.id AND tc.label = :tag_label)';
    $params[':tag_label'] = $tagFilter;
}

$photoCountExpr   = $hasPhotosTbl ? '(SELECT COUNT(*) FROM note_photos p WHERE p.note_id = n.id) AS photo_count' : '0 AS photo_count';
$commentCountExpr = $hasCommentsTbl ? '(SELECT COUNT(*) FROM note_comments c WHERE c.note_id = n.id) AS comment_count' : '0 AS comment_count';

$selectParts = [
    'n.*',
    '(n.user_id = :me_owner_select) AS is_owner',
    $isSharedExpr,
    $photoCountExpr,
    $commentCountExpr,
];
$params[':me_owner_select'] = $meId;

$joins = '';
if ($hasMetaTbl) {
    $selectParts[] = 'npm.icon AS meta_icon';
    $selectParts[] = 'npm.cover_url AS meta_cover_url';
    $selectParts[] = 'npm.status AS meta_status';
    $selectParts[] = 'npm.properties AS meta_properties';
    $joins .= ' LEFT JOIN note_pages_meta npm ON npm.note_id = n.id';
}

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$orderParts = [];
if ($hasNoteDate) {
    $orderParts[] = 'n.note_date DESC';
}
if ($hasUpdatedAt) {
    $orderParts[] = 'n.updated_at DESC';
}
if ($hasCreatedAt) {
    $orderParts[] = 'n.created_at DESC';
}
$orderParts[] = 'n.id DESC';
$orderSql = ' ORDER BY ' . implode(', ', $orderParts) . ' LIMIT 200';

$sql = 'SELECT ' . implode(",
          ", $selectParts) . "
        FROM notes n
        {$joins}
        {$whereSql}
        {$orderSql}";

$rows = [];
try {
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    error_log('Notes index query failed: ' . $e->getMessage());
    $rows = [];
}

$noteIds = array_map(static fn(array $row) => (int)($row['id'] ?? 0), $rows);
$noteIds = array_values(array_filter($noteIds));
$tagsMap = $noteIds ? notes_fetch_tags_for_notes($noteIds) : [];

$shareMap = [];
$shareLabels = [];
if ($noteIds) {
    $shareCol = notes__shares_column($pdo);
    if ($shareCol) {
        $placeholders = implode(',', array_fill(0, count($noteIds), '?'));
        $sqlShares = "SELECT note_id, {$shareCol} AS user_id FROM notes_shares WHERE note_id IN ($placeholders)";
        $stShares = $pdo->prepare($sqlShares);
        $stShares->execute($noteIds);
        $userIds = [];
        while ($shareRow = $stShares->fetch(PDO::FETCH_ASSOC)) {
            $nid = (int)($shareRow['note_id'] ?? 0);
            $uid = (int)($shareRow['user_id'] ?? 0);
            if ($nid <= 0 || $uid <= 0) {
                continue;
            }
            $shareMap[$nid] = $shareMap[$nid] ?? [];
            if (!in_array($uid, $shareMap[$nid], true)) {
                $shareMap[$nid][] = $uid;
                $userIds[] = $uid;
            }
        }
        if ($userIds) {
            $shareLabels = notes_fetch_users_map(array_unique($userIds));
        }
    }
}

foreach ($rows as &$row) {
    $noteId = (int)($row['id'] ?? 0);
    $metaData = [
        'icon'       => null,
        'cover_url'  => null,
        'status'     => NOTES_DEFAULT_STATUS,
        'properties' => notes_default_properties(),
    ];
    if ($hasMetaTbl) {
        if (isset($row['meta_icon']) && $row['meta_icon'] !== '') {
            $metaData['icon'] = $row['meta_icon'];
        }
        if (isset($row['meta_cover_url']) && $row['meta_cover_url'] !== '') {
            $metaData['cover_url'] = $row['meta_cover_url'];
        }
        if (isset($row['meta_status']) && $row['meta_status'] !== '') {
            $metaData['status'] = notes_normalize_status($row['meta_status']);
        }
        if (!empty($row['meta_properties'])) {
            $decoded = json_decode((string)$row['meta_properties'], true);
            $metaData['properties'] = notes_normalize_properties($decoded);
        }
    }
    $row['_meta']        = $metaData;
    $row['_status']      = $metaData['status'];
    $row['_properties']  = $metaData['properties'];
    $row['_tags']        = $tagsMap[$noteId] ?? [];
    $row['_share_ids']   = $shareMap[$noteId] ?? [];
    $row['_share_labels']= array_map(
        static fn($id) => $shareLabels[$id] ?? ('User #' . $id),
        $row['_share_ids']
    );
    $row['_share_count'] = count($row['_share_ids']);
    $row['_owner_label'] = notes_user_label((int)($row['user_id'] ?? 0));
    $row['_excerpt']     = notes__excerpt((string)($row['body'] ?? ''), 220);

    $timestamp = $row['updated_at'] ?? $row['created_at'] ?? $row['note_date'] ?? null;
    $row['_updated_relative'] = $timestamp ? notes__relative_time((string)$timestamp) : '';
    $row['_updated_absolute'] = '';
    if ($timestamp) {
        try {
            $row['_updated_absolute'] = (new DateTimeImmutable((string)$timestamp))->format('Y-m-d H:i');
        } catch (Throwable $e) {
            $row['_updated_absolute'] = (string)$timestamp;
        }
    }
    $row['_can_share']   = notes_can_share($row);
    $row['_payload'] = [
        'id'            => $noteId,
        'title'         => trim((string)($row['title'] ?? '')) ?: 'Untitled',
        'status'        => $row['_status'],
        'statusLabel'   => notes_status_label($row['_status']),
        'statusBadge'   => notes_status_badge_class($row['_status']),
        'icon'          => $metaData['icon'],
        'coverUrl'      => $metaData['cover_url'],
        'excerpt'       => $row['_excerpt'],
        'ownerId'       => (int)($row['user_id'] ?? 0),
        'ownerLabel'    => $row['_owner_label'],
        'isOwner'       => !empty($row['is_owner']),
        'isShared'      => !empty($row['is_shared']) && empty($row['is_owner']),
        'properties'    => $metaData['properties'],
        'tags'          => array_map(static function ($tag) {
            return [
                'label' => (string)($tag['label'] ?? ''),
                'color' => (string)($tag['color'] ?? ''),
            ];
        }, $row['_tags']),
        'shareIds'      => $row['_share_ids'],
        'shareLabels'   => $row['_share_labels'],
        'shareCount'    => $row['_share_count'],
        'photoCount'    => (int)($row['photo_count'] ?? 0),
        'commentCount'  => (int)($row['comment_count'] ?? 0),
        'noteDate'      => $row['note_date'] ?? null,
        'updatedRelative'=> $row['_updated_relative'],
        'updatedAbsolute'=> $row['_updated_absolute'],
        'canShare'      => $row['_can_share'],
        'links'         => [
            'view' => 'view.php?id=' . $noteId,
            'edit' => 'edit.php?id=' . $noteId,
        ],
    ];
}
unset($row);

$focusId = isset($_GET['note']) ? (int)$_GET['note'] : 0;
$activeNote = null;
foreach ($rows as $candidate) {
    if ($focusId > 0 && (int)($candidate['id'] ?? 0) === $focusId) {
        $activeNote = $candidate;
        break;
    }
}
if ($activeNote === null && $rows) {
    $activeNote = $rows[0];
}

$totalNotes       = count($rows);
$ownedCount       = 0;
$sharedCount      = 0;
$photoRichCount   = 0;
$commentRichCount = 0;
$photoTotal       = 0;
$latestTimestamp  = null;
$statusCounts     = array_fill_keys(array_keys($statuses), 0);

foreach ($rows as $row) {
    $isOwner  = !empty($row['is_owner']);
    $isShared = !empty($row['is_shared']) && !$isOwner;
    if ($isOwner) {
        $ownedCount++;
    }
    if ($isShared) {
        $sharedCount++;
    }
    $pc = (int)($row['photo_count'] ?? 0);
    $cc = (int)($row['comment_count'] ?? 0);
    if ($pc > 0) {
        $photoRichCount++;
    }
    if ($cc > 0) {
        $commentRichCount++;
    }
    $photoTotal += $pc;

    $status = $row['_status'] ?? NOTES_DEFAULT_STATUS;
    if (!isset($statusCounts[$status])) {
        $statusCounts[$status] = 0;
    }
    $statusCounts[$status]++;

    $candidateTimestamp = $row['updated_at'] ?? $row['created_at'] ?? $row['note_date'] ?? null;
    if ($candidateTimestamp !== null) {
        if ($latestTimestamp === null || strcmp((string)$candidateTimestamp, (string)$latestTimestamp) > 0) {
            $latestTimestamp = (string)$candidateTimestamp;
        }
    }
}

$avgPhotos        = $totalNotes > 0 ? $photoTotal / $totalNotes : 0.0;
$avgPhotosRounded = $avgPhotos > 0 ? round($avgPhotos, 1) : 0.0;
$percentOwned     = $totalNotes > 0 ? round(($ownedCount / $totalNotes) * 100) : 0;
$percentShared    = $totalNotes > 0 ? round(($sharedCount / $totalNotes) * 100) : 0;
$activeCount      = $totalNotes - ($statusCounts['archived'] ?? 0);
$completedCount   = $statusCounts['complete'] ?? 0;
$inProgressCount  = $statusCounts['in_progress'] ?? 0;
$reviewCount      = $statusCounts['review'] ?? 0;
$blockedCount     = $statusCounts['blocked'] ?? 0;

$lastUpdatedRelative = $latestTimestamp ? notes__relative_time($latestTimestamp) : '';
$lastUpdatedAbsolute = '';
if ($latestTimestamp) {
    try {
        $lastUpdatedAbsolute = (new DateTimeImmutable($latestTimestamp))->format('Y-m-d H:i');
    } catch (Throwable $e) {
        $lastUpdatedAbsolute = (string)$latestTimestamp;
    }
}

$statusColumns = [];
foreach ($statuses as $slug => $label) {
    $statusColumns[$slug] = [];
}
foreach ($rows as $row) {
    $slug = $row['_status'] ?? NOTES_DEFAULT_STATUS;
    if (!isset($statusColumns[$slug])) {
        $statusColumns[$slug] = [];
    }
    $statusColumns[$slug][] = $row;
}

$tagOptions = notes_all_tag_options();
$lastUpdateHint = $lastUpdatedRelative !== ''
    ? 'Updated ' . $lastUpdatedRelative
    : 'Waiting for first update';

$templates       = notes_fetch_templates_for_user($meId);
$ownedTemplates  = [];
$sharedTemplates = [];
foreach ($templates as $template) {
    $templateId = (int)($template['id'] ?? 0);
    $details    = $templateId > 0 ? notes_template_share_details($templateId) : [];
    $shareIds   = array_map(static fn($share) => (int)($share['id'] ?? 0), $details);
    $shareLabels= array_map(static fn($share) => (string)($share['label'] ?? ''), $details);
    $normalized = $template;
    $normalized['share_details'] = $details;
    $normalized['share_ids']     = $shareIds;
    $normalized['share_labels']  = $shareLabels;
    $normalized['owner_label']   = notes_user_label((int)($template['owner_id'] ?? ($template['user_id'] ?? 0)));
    $normalized['payload']       = [
        'id'          => $templateId,
        'name'        => (string)($template['name'] ?? ''),
        'title'       => (string)($template['title'] ?? ''),
        'icon'        => $template['icon'] ?? null,
        'coverUrl'    => $template['coverUrl'] ?? null,
        'status'      => $template['status'] ?? NOTES_DEFAULT_STATUS,
        'statusLabel' => notes_status_label($template['status'] ?? NOTES_DEFAULT_STATUS),
        'properties'  => $template['properties'] ?? notes_default_properties(),
        'tags'        => $template['tags'] ?? [],
        'shareIds'    => $shareIds,
        'shareLabels' => $shareLabels,
        'ownerId'     => (int)($template['owner_id'] ?? ($template['user_id'] ?? 0)),
        'ownerLabel'  => $normalized['owner_label'],
        'isOwner'     => !empty($template['is_owner']),
        'sharedFrom'  => $template['shared_from'] ?? null,
    ];
    if (!empty($template['is_owner'])) {
        $ownedTemplates[] = $normalized;
    } else {
        $sharedTemplates[] = $normalized;
    }
}

$shareOptions = [];
foreach (notes_all_users() as $user) {
    $uid = (int)($user['id'] ?? 0);
    if ($uid <= 0) {
        continue;
    }
    $label = trim((string)($user['email'] ?? ''));
    if ($label === '') {
        $label = 'User #' . $uid;
    }
    $shareOptions[] = [
        'id'    => $uid,
        'label' => $label,
    ];
}

$csrfToken = csrf_token();

$propertyLabels = notes_property_labels();

$title = 'Notes';
include __DIR__ . '/../includes/header.php';
?>


<section class="obsidian-shell" data-theme="obsidian" data-index-shell data-csrf="<?php echo  sanitize($csrfToken); ?>">
  <header class="obsidian-header">
    <div class="obsidian-header__titles">
      <span class="obsidian-header__eyebrow">Vault overview</span>
      <h1>Notes workspace</h1>
    </div>
    <div class="obsidian-header__actions">
      <button type="button" class="obsidian-header__command" data-command-open>⌘K Command palette</button>
      <button type="button" class="obsidian-header__quick" data-quick-open>Quick capture</button>
      <a class="btn obsidian-header__new" href="new.php">New note</a>
    </div>
  </header>
  <div class="obsidian-layout">
    <aside class="obsidian-sidebar">
      <form method="get" action="index.php" class="obsidian-search" autocomplete="off">
        <label class="obsidian-search__field">
          <span>Search vault</span>
          <input type="search" name="q" value="<?php echo  sanitize($search); ?>" placeholder="Title, tag, or text">
        </label>
        <?php if ($statusFilter !== ''): ?>
          <input type="hidden" name="status" value="<?php echo  sanitize($statusFilter); ?>">
        <?php endif; ?>
        <?php if ($tagFilter !== ''): ?>
          <input type="hidden" name="tag" value="<?php echo  sanitize($tagFilter); ?>">
        <?php endif; ?>
        <div class="obsidian-search__meta">
          <span class="obsidian-search__hint">Press <kbd>/</kbd> to focus search</span>
          <div class="obsidian-search__meta-actions">
            <?php if ($statusFilter !== ''): ?>
              <span class="obsidian-search__chip">Status: <?php echo  sanitize($statuses[$statusFilter] ?? notes_status_label($statusFilter)); ?></span>
            <?php endif; ?>
            <?php if ($tagFilter !== ''): ?>
              <span class="obsidian-search__chip">Tag: <?php echo  sanitize($tagFilter); ?></span>
            <?php endif; ?>
            <?php if ($search !== '' || $statusFilter !== '' || $tagFilter !== ''): ?>
              <a class="obsidian-search__reset" href="index.php">Clear filters</a>
            <?php endif; ?>
          </div>
        </div>
      </form>
      <div class="obsidian-summary">
        <div data-summary-total><span>Total notes</span><strong><?php echo  number_format($totalNotes); ?></strong></div>
        <div data-summary-shared><span>Shared</span><strong><?php echo  number_format($sharedCount); ?></strong></div>
        <div data-summary-comments><span>Comments</span><strong><?php echo  number_format($commentRichCount); ?></strong></div>
        <div data-summary-photos><span>Photos</span><strong><?php echo  number_format($photoRichCount); ?></strong></div>
        <div data-summary-updated><span>Updated</span><strong><?php echo  sanitize($lastUpdateHint); ?></strong></div>
      </div>
      <div class="obsidian-statuses">
        <h2>Status lanes</h2>
        <ul>
          <?php foreach ($statuses as $slug => $label): ?>
            <li>
              <span class="badge <?php echo  sanitize(notes_status_badge_class($slug)); ?>"><?php echo  sanitize($label); ?></span>
              <span><?php echo  number_format(count($statusColumns[$slug] ?? [])); ?></span>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>
      <div class="obsidian-templates">
        <header>
          <h2>Templates</h2>
          <p>Capture repeatable note layouts.</p>
        </header>
        <?php if ($ownedTemplates): ?>
          <h3>My templates</h3>
          <ul>
            <?php foreach ($ownedTemplates as $tpl): ?>
              <?php
                $tplId   = (int)($tpl['id'] ?? 0);
                $tplName = trim((string)($tpl['name'] ?? 'Untitled'));
                $tplIcon = trim((string)($tpl['icon'] ?? ''));
                $tplDisplayIcon = $tplIcon !== '' ? $tplIcon : '📄';
                $tplPayload = htmlspecialchars(json_encode($tpl['payload'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8');
              ?>
              <li class="obsidian-template" data-template-id="<?php echo  $tplId; ?>">
                <div class="obsidian-template__row">
                  <a class="obsidian-template__apply" href="new.php?template=<?php echo  $tplId; ?>">
                    <span class="obsidian-template__icon"><?php echo  sanitize($tplDisplayIcon); ?></span>
                    <span>
                      <strong><?php echo  sanitize($tplName); ?></strong>
                      <?php if (!empty($tpl['title'])): ?><em><?php echo  sanitize($tpl['title']); ?></em><?php endif; ?>
                    </span>
                  </a>
                  <button type="button" class="obsidian-template__share" data-template-share-button="<?php echo  $tplId; ?>" data-template-share="<?php echo  $tplPayload; ?>">Share</button>
                </div>
                <div class="obsidian-template__sharelist" data-template-share-list="<?php echo  $tplId; ?>">
                  <?php if (!empty($tpl['share_labels'])): ?>
                    <?php foreach ($tpl['share_labels'] as $label): ?>
                      <span class="obsidian-pill"><?php echo  sanitize($label); ?></span>
                    <?php endforeach; ?>
                  <?php else: ?>
                    <span class="obsidian-pill is-muted">Private</span>
                  <?php endif; ?>
                </div>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php else: ?>
          <p class="obsidian-template__empty">Save a note as a template to jump-start new pages.</p>
        <?php endif; ?>
        <?php if ($sharedTemplates): ?>
          <h3>Shared with me</h3>
          <ul>
            <?php foreach ($sharedTemplates as $tpl): ?>
              <?php
                $tplId   = (int)($tpl['id'] ?? 0);
                $tplName = trim((string)($tpl['name'] ?? 'Untitled'));
                $tplIcon = trim((string)($tpl['icon'] ?? ''));
                $tplDisplayIcon = $tplIcon !== '' ? $tplIcon : '📄';
              ?>
              <li class="obsidian-template obsidian-template--shared" data-template-id="<?php echo  $tplId; ?>">
                <a class="obsidian-template__apply" href="new.php?template=<?php echo  $tplId; ?>">
                  <span class="obsidian-template__icon"><?php echo  sanitize($tplDisplayIcon); ?></span>
                  <span>
                    <strong><?php echo  sanitize($tplName); ?></strong>
                    <?php if (!empty($tpl['shared_from'])): ?><em>Shared by <?php echo  sanitize($tpl['shared_from']); ?></em><?php endif; ?>
                  </span>
                </a>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>
    </aside>
    <div class="obsidian-main">
      <div class="obsidian-note-list" data-note-list>
        <?php if (!$rows): ?>
          <div class="obsidian-empty">
            <p>No notes yet.</p>
            <a class="btn obsidian-primary" href="new.php">Create a note</a>
          </div>
        <?php else: ?>
          <?php foreach ($rows as $note): ?>
            <?php
              $noteId = (int)($note['id'] ?? 0);
              $payloadJson = htmlspecialchars(json_encode($note['_payload'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8');
              $title = trim((string)($note['title'] ?? ''));
              if ($title === '') { $title = 'Untitled'; }
              $icon = trim((string)($note['_meta']['icon'] ?? ''));
              if ($icon === '') {
                  $firstChar = function_exists('mb_substr') ? mb_substr($title, 0, 1, 'UTF-8') : substr($title, 0, 1);
                  $icon = $firstChar !== '' ? strtoupper($firstChar) : '📝';
              }
              $status = $note['_status'] ?? NOTES_DEFAULT_STATUS;
              $statusLabel = notes_status_label($status);
              $statusClass = notes_status_badge_class($status);
            ?>
            <article class="obsidian-note<?php echo  ($activeNote && (int)$activeNote['id'] === $noteId) ? ' is-active' : ''; ?>" data-note-id="<?php echo  $noteId; ?>" data-note='<?php echo  $payloadJson; ?>' data-note-item>
              <header class="obsidian-note__header">
                <span class="obsidian-note__icon"><?php echo  sanitize($icon); ?></span>
                <div class="obsidian-note__titles">
                  <h3><?php echo  sanitize($title); ?></h3>
                  <div class="obsidian-note__meta">
                    <span class="badge <?php echo  sanitize($statusClass); ?>"><?php echo  sanitize($statusLabel); ?></span>
                    <?php if ($note['_updated_relative']): ?>
                      <span class="obsidian-note__timestamp"><?php echo  sanitize($note['_updated_relative']); ?></span>
                    <?php endif; ?>
                    <?php if (!empty($note['_is_shared'])): ?>
                      <span class="obsidian-note__shared">Shared</span>
                    <?php endif; ?>
                  </div>
                </div>
                <div class="obsidian-note__counts">
                  <span class="obsidian-note__pill" data-note-share-count="<?php echo  $noteId; ?>">👥 <?php echo  number_format($note['_share_count']); ?></span>
                  <span class="obsidian-note__pill">💬 <?php echo  number_format((int)($note['comment_count'] ?? 0)); ?></span>
                  <span class="obsidian-note__pill">📸 <?php echo  number_format((int)($note['photo_count'] ?? 0)); ?></span>
                </div>
              </header>
              <?php if ($note['_excerpt']): ?>
                <p class="obsidian-note__excerpt"><?php echo  sanitize($note['_excerpt']); ?></p>
              <?php endif; ?>
              <?php if ($note['_tags']): ?>
                <div class="obsidian-note__tags">
                  <?php foreach ($note['_tags'] as $tag): $color = $tag['color'] ?? '#6366F1'; ?>
                    <span class="obsidian-tag" style="--tag-color: <?php echo  sanitize($color); ?>"><?php echo  sanitize($tag['label'] ?? ''); ?></span>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
            </article>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>
    <aside class="obsidian-preview" data-note-preview>
      <?php if ($activeNote): ?>
        <?php
          $payload = $activeNote['_payload'];
          $previewTitle = trim((string)($payload['title'] ?? 'Untitled'));
          if ($previewTitle === '') { $previewTitle = 'Untitled'; }
          $previewIcon = trim((string)($payload['icon'] ?? ''));
          if ($previewIcon === '') {
              $firstChar = function_exists('mb_substr') ? mb_substr($previewTitle, 0, 1, 'UTF-8') : substr($previewTitle, 0, 1);
              $previewIcon = $firstChar !== '' ? strtoupper($firstChar) : '📝';
          }
        ?>
        <div class="obsidian-preview__scroll" data-active-note-id="<?php echo  (int)($payload['id'] ?? 0); ?>">
          <div class="obsidian-preview__header">
            <span class="obsidian-preview__icon" data-preview-icon><?php echo  sanitize($previewIcon); ?></span>
            <div>
              <span class="badge <?php echo  sanitize($payload['statusBadge']); ?>" data-preview-status><?php echo  sanitize($payload['statusLabel']); ?></span>
              <h2 data-preview-title><?php echo  sanitize($previewTitle); ?></h2>
              <?php if (!empty($payload['updatedRelative'])): ?>
                <p class="obsidian-preview__timestamp">Updated <?php echo  sanitize($payload['updatedRelative']); ?></p>
              <?php endif; ?>
            </div>
          </div>
          <div class="obsidian-preview__actions">
            <a class="btn obsidian-primary" href="<?php echo  sanitize($payload['links']['view']); ?>" data-preview-view>Open note</a>
            <a class="btn obsidian-btn" href="<?php echo  sanitize($payload['links']['edit']); ?>" data-preview-edit>Edit</a>
            <?php if (!empty($payload['canShare'])): ?>
              <button type="button" class="btn obsidian-btn--ghost" data-open-note-share="<?php echo  (int)($payload['id'] ?? 0); ?>">Manage access</button>
            <?php endif; ?>
          </div>
          <dl class="obsidian-preview__meta">
            <div>
              <dt>Owner</dt>
              <dd data-preview-owner><?php echo  sanitize($payload['ownerLabel']); ?></dd>
            </div>
            <div>
              <dt>Photos</dt>
              <dd data-preview-photos><?php echo  number_format((int)($payload['photoCount'] ?? 0)); ?></dd>
            </div>
            <div>
              <dt>Comments</dt>
              <dd data-preview-comments><?php echo  number_format((int)($payload['commentCount'] ?? 0)); ?></dd>
            </div>
            <div>
              <dt>Note date</dt>
              <dd data-preview-date><?php echo  sanitize(notes__format_date($payload['noteDate'] ?? '')); ?></dd>
            </div>
          </dl>
          <div class="obsidian-preview__properties" data-preview-properties>
            <?php foreach ($payload['properties'] as $key => $value): $value = trim((string)$value); if ($value === '') continue; ?>
              <div><span><?php echo  sanitize($propertyLabels[$key] ?? ucfirst($key)); ?></span><strong><?php echo  sanitize($value); ?></strong></div>
            <?php endforeach; ?>
          </div>
          <div class="obsidian-preview__tags" data-preview-tags>
            <?php foreach ($payload['tags'] as $tag): $color = $tag['color'] ?? '#6366F1'; ?>
              <span class="obsidian-tag" style="--tag-color: <?php echo  sanitize($color); ?>"><?php echo  sanitize($tag['label'] ?? ''); ?></span>
            <?php endforeach; ?>
          </div>
          <div class="obsidian-preview__shares">
            <h3>Collaborators</h3>
            <div class="obsidian-preview__sharelist" data-preview-shares>
              <?php if (!empty($payload['shareLabels'])): ?>
                <?php foreach ($payload['shareLabels'] as $label): ?>
                  <span class="obsidian-pill"><?php echo  sanitize($label); ?></span>
                <?php endforeach; ?>
              <?php else: ?>
                <span class="obsidian-pill is-muted">Private</span>
              <?php endif; ?>
            </div>
          </div>
        </div>
      <?php else: ?>
        <div class="obsidian-preview__empty">
          <p>Select a note to see its details.</p>
        </div>
      <?php endif; ?>
    </aside>
  </div>
</section>

<div class="obsidian-modal hidden" id="quickCaptureModal" data-modal>
  <div class="obsidian-modal__overlay" data-modal-close></div>
  <div class="obsidian-modal__dialog obsidian-modal__dialog--capture" role="dialog" aria-modal="true">
    <header class="obsidian-modal__header">
      <div>
        <h3>Quick capture</h3>
        <p class="obsidian-modal__subtitle">Draft a lightweight page without leaving the vault.</p>
      </div>
      <button type="button" class="obsidian-modal__close" data-modal-close>&times;</button>
    </header>
    <form method="post" class="obsidian-modal__form" data-quick-form autocomplete="off">
      <input type="hidden" name="<?php echo  CSRF_TOKEN_NAME; ?>" value="<?php echo  sanitize($csrfToken); ?>">
      <input type="hidden" name="quick_note" value="1">
      <div class="obsidian-modal__body">
        <label class="obsidian-field">
          <span>Title</span>
          <input type="text" name="title" required maxlength="180" placeholder="New note title">
        </label>
        <div class="obsidian-modal__grid">
          <label class="obsidian-field">
            <span>Status</span>
            <select name="status">
              <?php foreach ($statuses as $slug => $label): ?>
                <option value="<?php echo  sanitize($slug); ?>"<?php echo  $slug === NOTES_DEFAULT_STATUS ? ' selected' : ''; ?>><?php echo  sanitize($label); ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label class="obsidian-field">
            <span>Date</span>
            <input type="date" name="note_date" value="<?php echo  sanitize($today); ?>">
          </label>
        </div>
        <div class="obsidian-modal__grid">
          <label class="obsidian-field">
            <span>Icon</span>
            <input type="text" name="icon" maxlength="4" placeholder="📝">
          </label>
          <label class="obsidian-field">
            <span>Cover URL</span>
            <input type="url" name="cover_url" placeholder="https://example.com/cover.jpg">
          </label>
        </div>
        <label class="obsidian-field">
          <span>Summary</span>
          <textarea name="body" rows="3" placeholder="Outline the idea..."></textarea>
        </label>
        <label class="obsidian-field">
          <span>Tags</span>
          <input type="text" name="quick_tags" placeholder="design, sprint, review">
        </label>
        <label class="obsidian-modal__toggle">
          <input type="checkbox" name="quick_open" value="1" checked>
          <span>Open in editor after capturing</span>
        </label>
        <p class="obsidian-modal__status" data-quick-status role="alert"></p>
      </div>
      <div class="obsidian-modal__footer">
        <button type="button" class="btn obsidian-btn--ghost" data-modal-close>Cancel</button>
        <button type="submit" class="btn obsidian-primary">Capture note</button>
      </div>
    </form>
  </div>
</div>

<div class="obsidian-modal hidden" id="commandPalette" data-modal>
  <div class="obsidian-modal__overlay" data-modal-close></div>
  <div class="obsidian-modal__dialog" role="dialog" aria-modal="true">
    <header>
      <input type="search" placeholder="Jump to note..." data-command-input>
    </header>
    <ul class="obsidian-modal__results" data-command-results></ul>
    <footer class="obsidian-modal__hint">Enter to open · ↑↓ to navigate · Esc to close</footer>
  </div>
</div>

<div class="share-modal hidden" id="noteShareModal" data-modal>
  <div class="share-modal__overlay" data-modal-close></div>
  <div class="share-modal__dialog" role="dialog" aria-modal="true">
    <header class="share-modal__header">
      <div>
        <h3>Share note</h3>
        <p class="share-modal__subtitle" data-share-note-title></p>
      </div>
      <button type="button" class="share-modal__close" data-modal-close>&times;</button>
    </header>
    <form method="post" class="share-modal__form" data-note-share-form>
      <input type="hidden" name="<?php echo  CSRF_TOKEN_NAME; ?>" value="<?php echo  sanitize($csrfToken); ?>">
      <input type="hidden" name="update_note_shares" value="1">
      <input type="hidden" name="note_id" value="">
      <div class="share-modal__body">
        <?php foreach ($shareOptions as $option): ?>
          <label class="share-modal__option" data-share-option data-user-id="<?php echo  (int)$option['id']; ?>" data-label="<?php echo  sanitize($option['label']); ?>">
            <input type="checkbox" name="shared_ids[]" value="<?php echo  (int)$option['id']; ?>">
            <span><?php echo  sanitize($option['label']); ?></span>
          </label>
        <?php endforeach; ?>
      </div>
      <div class="share-modal__footer">
        <span class="share-modal__status" data-share-status></span>
        <button type="submit" class="btn obsidian-primary">Save access</button>
      </div>
    </form>
  </div>
</div>

<div class="share-modal hidden" id="templateShareModal" data-modal>
  <div class="share-modal__overlay" data-modal-close></div>
  <div class="share-modal__dialog" role="dialog" aria-modal="true">
    <header class="share-modal__header">
      <div>
        <h3>Share template</h3>
        <p class="share-modal__subtitle" data-share-template-title></p>
      </div>
      <button type="button" class="share-modal__close" data-modal-close>&times;</button>
    </header>
    <form method="post" class="share-modal__form" data-template-share-form>
      <input type="hidden" name="<?php echo  CSRF_TOKEN_NAME; ?>" value="<?php echo  sanitize($csrfToken); ?>">
      <input type="hidden" name="update_template_shares" value="1">
      <input type="hidden" name="template_id" value="">
      <div class="share-modal__body">
        <?php foreach ($shareOptions as $option): ?>
          <label class="share-modal__option" data-template-share-option data-user-id="<?php echo  (int)$option['id']; ?>" data-label="<?php echo  sanitize($option['label']); ?>">
            <input type="checkbox" name="shared_ids[]" value="<?php echo  (int)$option['id']; ?>">
            <span><?php echo  sanitize($option['label']); ?></span>
          </label>
        <?php endforeach; ?>
      </div>
      <div class="share-modal__footer">
        <span class="share-modal__status" data-template-share-status></span>
        <button type="submit" class="btn obsidian-primary">Save access</button>
      </div>
    </form>
  </div>
</div>

<style>
.obsidian-shell{position:relative;background:#fff;color:#0f172a;border-radius:18px;padding:1.2rem 1.4rem;margin-bottom:1.35rem;border:1px solid #e2e8f0;box-shadow:0 14px 32px rgba(15,23,42,.08);}
.obsidian-header{display:flex;justify-content:space-between;align-items:center;gap:.9rem;margin-bottom:1.3rem;flex-wrap:wrap;}
.obsidian-header__titles h1{margin:0;font-size:1.58rem;font-weight:700;color:#0f172a;}
.obsidian-header__eyebrow{text-transform:uppercase;letter-spacing:.14em;font-size:.72rem;color:#64748b;display:block;margin-bottom:.2rem;}
.obsidian-header__actions{display:flex;gap:.55rem;align-items:center;flex-wrap:wrap;}
.obsidian-header__command{background:#edf2f7;border:1px solid #cbd5f5;color:#1e293b;border-radius:999px;padding:.45rem 1rem;font-size:.88rem;cursor:pointer;transition:.2s ease;}
.obsidian-header__command:hover{background:#e2e8f0;}
.obsidian-header__quick{background:#f8fafc;border:1px solid #cbd5f5;color:#2563eb;border-radius:999px;padding:.45rem .95rem;font-weight:500;font-size:.88rem;cursor:pointer;transition:.2s ease;}
.obsidian-header__quick:hover{background:#eff6ff;border-color:#bfdbfe;color:#1d4ed8;}
.obsidian-header__new{background:#2563eb;color:#fff;border-radius:999px;padding:.5rem 1.2rem;font-weight:600;box-shadow:0 10px 22px rgba(37,99,235,.18);text-decoration:none;}
.obsidian-layout{display:grid;gap:1.15rem;grid-template-columns:272px minmax(0,1fr) 312px;align-items:start;}
.obsidian-sidebar{background:#f8fafc;border:1px solid #e2e8f0;border-radius:16px;padding:1rem;display:grid;gap:1rem;}
.obsidian-main{background:#f8fafc;border:1px solid #e2e8f0;border-radius:16px;padding:1rem;min-height:480px;display:grid;gap:.95rem;}
.obsidian-preview{background:#f8fafc;border:1px solid #e2e8f0;border-radius:16px;padding:1rem;min-height:480px;display:grid;}
.obsidian-search{display:grid;gap:.8rem;background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:.85rem;}
.obsidian-search__field{display:grid;gap:.35rem;font-size:.82rem;color:#475569;}
.obsidian-search input{background:#fff;border:1px solid #cbd5f5;border-radius:.75rem;padding:.5rem .75rem;color:#0f172a;font-size:.93rem;}
.obsidian-search__meta{display:flex;align-items:center;justify-content:space-between;gap:.6rem;flex-wrap:wrap;color:#64748b;font-size:.78rem;}
.obsidian-search__hint{display:flex;align-items:center;gap:.3rem;}
.obsidian-search__hint kbd{background:#e2e8f0;border-radius:6px;padding:.1rem .35rem;font-family:'JetBrains Mono',monospace;font-size:.72rem;color:#1e293b;}
.obsidian-search__meta-actions{display:flex;align-items:center;gap:.45rem;flex-wrap:wrap;}
.obsidian-search__chip{background:#e0f2fe;color:#0369a1;border-radius:999px;padding:.2rem .55rem;font-size:.72rem;font-weight:500;}
.obsidian-search__reset{color:#2563eb;text-decoration:none;border-bottom:1px solid transparent;transition:border-color .2s ease,color .2s ease;}
.obsidian-search__reset:hover{color:#1d4ed8;border-color:#1d4ed8;}
.obsidian-btn{background:#e0e7ff;border:1px solid #c7d2fe;color:#1d4ed8;border-radius:999px;padding:.4rem 1rem;cursor:pointer;font-weight:500;transition:background .2s ease,border-color .2s ease,color .2s ease;}
.obsidian-btn--ghost{background:#fff;border:1px solid #cbd5f5;color:#1e293b;border-radius:999px;padding:.4rem 1rem;cursor:pointer;font-weight:500;transition:background .2s ease,border-color .2s ease,color .2s ease;}
.obsidian-primary{background:#2563eb;border:none;color:#fff;border-radius:999px;padding:.5rem 1.2rem;font-weight:600;box-shadow:0 10px 20px rgba(37,99,235,.18);cursor:pointer;}
.obsidian-summary{display:grid;gap:.35rem;background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:.8rem;font-size:.85rem;color:#475569;}
.obsidian-summary span{color:#94a3b8;font-size:.72rem;text-transform:uppercase;letter-spacing:.08em;display:block;}
.obsidian-summary strong{color:#0f172a;font-size:.98rem;}
.obsidian-statuses ul{list-style:none;margin:0;padding:0;display:grid;gap:.4rem;}
.obsidian-statuses li{display:flex;justify-content:space-between;align-items:center;background:#fff;border-radius:10px;padding:.45rem .65rem;border:1px solid #e2e8f0;font-size:.85rem;color:#1f2937;}
.obsidian-templates header h2{margin:0;font-size:1rem;color:#0f172a;}
.obsidian-templates header p{margin:.25rem 0 0;color:#64748b;font-size:.82rem;}
.obsidian-templates h3{margin:.6rem 0 .35rem;font-size:.78rem;color:#94a3b8;text-transform:uppercase;letter-spacing:.1em;}
.obsidian-template__empty{margin:0;font-size:.8rem;color:#94a3b8;}
.obsidian-templates ul{list-style:none;margin:0;padding:0;display:grid;gap:.5rem;}
.obsidian-template{background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:.6rem .7rem;display:grid;gap:.45rem;}
.obsidian-template--shared{background:#ecfeff;border-color:#99f6e4;}
.obsidian-template__row{display:flex;justify-content:space-between;align-items:center;gap:.5rem;}
.obsidian-template__apply{text-decoration:none;color:#0f172a;display:flex;gap:.55rem;align-items:center;}
.obsidian-template__icon{width:30px;height:30px;border-radius:9px;background:#e0e7ff;display:grid;place-items:center;font-size:1rem;color:#1d4ed8;}
.obsidian-template__apply strong{display:block;font-weight:600;color:#0f172a;}
.obsidian-template__apply em{display:block;font-size:.74rem;color:#64748b;font-style:normal;}
.obsidian-template__share{background:#e0e7ff;border:1px solid #c7d2fe;color:#1d4ed8;border-radius:999px;padding:.3rem .75rem;font-size:.75rem;cursor:pointer;}
.obsidian-template__sharelist{display:flex;gap:.3rem;flex-wrap:wrap;}
.badge{display:inline-flex;align-items:center;gap:.25rem;padding:.25rem .55rem;border-radius:999px;font-size:.7rem;font-weight:600;}
.badge--muted{background:#e2e8f0;color:#475569;}
.badge--blue{background:#dbeafe;color:#1d4ed8;}
.badge--indigo{background:#e0e7ff;color:#4338ca;}
.badge--purple{background:#ede9fe;color:#7c3aed;}
.badge--orange{background:#fef3c7;color:#b45309;}
.badge--green{background:#dcfce7;color:#047857;}
.badge--slate{background:#f1f5f9;color:#475569;}
.badge--danger{background:#fee2e2;color:#b91c1c;}
.badge--amber{background:#fef3c7;color:#92400e;}
.badge--teal{background:#ccfbf1;color:#0f766e;}
.obsidian-note-list{display:grid;gap:.6rem;}
.obsidian-note{background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:.75rem .8rem;display:grid;gap:.55rem;cursor:pointer;transition:transform .15s ease,border-color .15s ease,box-shadow .15s ease;box-shadow:0 6px 16px rgba(15,23,42,.06);}
.obsidian-note:hover{transform:translateY(-1px);border-color:#c7d2fe;box-shadow:0 10px 22px rgba(15,23,42,.08);}
.obsidian-note.is-active{border-color:#2563eb;box-shadow:0 14px 28px rgba(37,99,235,.14);}
.obsidian-note__header{display:flex;gap:.65rem;align-items:flex-start;justify-content:space-between;}
.obsidian-note__icon{width:38px;height:38px;border-radius:10px;background:#e0e7ff;display:grid;place-items:center;font-size:1.1rem;color:#1d4ed8;}
.obsidian-note__titles h3{margin:0;font-size:1.05rem;color:#0f172a;}
.obsidian-note__meta{display:flex;gap:.35rem;align-items:center;font-size:.74rem;color:#64748b;flex-wrap:wrap;}
.obsidian-note__timestamp{color:#475569;}
.obsidian-note__shared{color:#0f766e;font-weight:600;}
.obsidian-note__counts{display:flex;gap:.35rem;align-items:center;}
.obsidian-note__pill{background:#edf2f7;border-radius:999px;padding:.2rem .55rem;font-size:.72rem;color:#475569;}
.obsidian-note__excerpt{margin:0;font-size:.88rem;color:#1f2937;}
.obsidian-note__tags{display:flex;gap:.35rem;flex-wrap:wrap;}
.obsidian-tag{display:inline-flex;align-items:center;gap:.3rem;background:#e0e7ff;border-radius:999px;padding:.18rem .55rem;font-size:.72rem;color:#1d4ed8;}
.obsidian-tag::before{content:'';width:8px;height:8px;border-radius:50%;background:var(--tag-color,#6366f1);}
.obsidian-preview__scroll{display:grid;gap:.85rem;max-height:500px;overflow:auto;padding-right:.2rem;}
.obsidian-preview__header{display:flex;gap:.75rem;align-items:center;}
.obsidian-preview__icon{width:46px;height:46px;border-radius:12px;background:#e0e7ff;display:grid;place-items:center;font-size:1.25rem;color:#1d4ed8;}
.obsidian-preview__timestamp{margin:.25rem 0 0;color:#64748b;font-size:.8rem;}
.obsidian-preview__actions{display:flex;gap:.45rem;flex-wrap:wrap;}
.obsidian-preview__meta{display:grid;gap:.5rem;grid-template-columns:repeat(2,minmax(0,1fr));background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:.75rem;font-size:.85rem;color:#1f2937;}
.obsidian-preview__meta dt{margin:0;font-size:.7rem;letter-spacing:.08em;text-transform:uppercase;color:#94a3b8;}
.obsidian-preview__meta dd{margin:0;font-size:.9rem;color:#0f172a;}
.obsidian-preview__properties{display:grid;gap:.45rem;background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:.75rem;font-size:.85rem;color:#1f2937;}
.obsidian-preview__properties span{display:block;font-size:.7rem;text-transform:uppercase;color:#94a3b8;letter-spacing:.08em;}
.obsidian-preview__properties strong{color:#0f172a;}
.obsidian-preview__tags{display:flex;gap:.35rem;flex-wrap:wrap;}
.obsidian-preview__shares h3{margin:0 0 .3rem;font-size:.95rem;color:#0f172a;}
.obsidian-preview__sharelist{display:flex;gap:.35rem;flex-wrap:wrap;}
.obsidian-preview__empty{display:grid;place-items:center;height:100%;font-size:.9rem;color:#94a3b8;text-align:center;background:#fff;border:1px dashed #e2e8f0;border-radius:12px;padding:1.2rem;}
.obsidian-empty{text-align:center;display:grid;gap:.6rem;justify-items:center;background:#fff;border:1px dashed #e2e8f0;border-radius:14px;padding:1.6rem;color:#64748b;}
.obsidian-pill{display:inline-flex;align-items:center;background:#e0e7ff;border-radius:999px;padding:.25rem .6rem;font-size:.75rem;color:#1d4ed8;font-weight:500;}
.obsidian-pill.is-muted{background:#edf2f7;color:#64748b;}
.obsidian-modal{position:fixed;inset:0;z-index:50;display:grid;place-items:center;padding:1.5rem;background:rgba(15,23,42,.25);backdrop-filter:blur(6px);transition:opacity .2s ease;}
.obsidian-modal.hidden{opacity:0;pointer-events:none;}
.obsidian-modal__overlay{position:absolute;inset:0;}
.obsidian-modal__dialog{position:relative;z-index:1;width:min(520px,100%);background:#fff;border:1px solid #e2e8f0;border-radius:16px;display:grid;gap:.65rem;padding:1rem 1.1rem 1.2rem;box-shadow:0 22px 44px rgba(15,23,42,.12);}
.obsidian-modal__dialog--capture{width:min(560px,100%);}
.obsidian-modal__dialog header input{width:100%;background:#fff;border:1px solid #cbd5f5;border-radius:.75rem;padding:.5rem .75rem;color:#0f172a;font-size:.95rem;}
.obsidian-modal__body{display:grid;gap:.65rem;}
.obsidian-modal__grid{display:grid;gap:.65rem;grid-template-columns:repeat(2,minmax(0,1fr));}
.obsidian-modal__toggle{display:flex;align-items:center;gap:.5rem;font-size:.85rem;color:#475569;}
.obsidian-modal__toggle input{width:1rem;height:1rem;}
.obsidian-modal__status{margin:.2rem 0 0;color:#64748b;font-size:.8rem;min-height:1.2rem;}
.obsidian-modal__status.is-error{color:#b91c1c;}
.obsidian-modal__status.is-success{color:#047857;}
.obsidian-modal__footer{display:flex;justify-content:flex-end;gap:.6rem;align-items:center;padding-top:.35rem;}
.obsidian-modal__results{list-style:none;margin:0;padding:0;display:grid;gap:.3rem;max-height:300px;overflow:auto;}
.obsidian-modal__results li{border-radius:10px;padding:.5rem .7rem;background:#f8fafc;border:1px solid #e2e8f0;color:#1f2937;cursor:pointer;transition:border-color .2s ease,background .2s ease;}
.obsidian-modal__results li.is-active{border-color:#2563eb;background:#dbeafe;}
.obsidian-modal__hint{font-size:.75rem;color:#64748b;}
@media (max-width:1200px){.obsidian-layout{grid-template-columns:260px minmax(0,1fr);} .obsidian-preview{display:none;}}
@media (max-width:960px){.obsidian-layout{grid-template-columns:minmax(0,1fr);} .obsidian-sidebar{grid-column:1 / -1;} .obsidian-main{grid-column:1 / -1;}}
@media (max-width:720px){.obsidian-shell{padding:1.25rem;} .obsidian-header__titles h1{font-size:1.45rem;} .obsidian-layout{grid-template-columns:minmax(0,1fr);} .obsidian-sidebar,.obsidian-main,.obsidian-preview{grid-column:1 / -1;}}
</style>

<script>
(() => {
  const shell = document.querySelector('[data-index-shell]');
  if (!shell) return;

  const noteState = new Map();
  const noteList = shell.querySelector('[data-note-list]');
  let noteElements = Array.from(shell.querySelectorAll('[data-note-item]'));
  const summaryTotal = shell.querySelector('[data-summary-total] strong');
  const summaryShared = shell.querySelector('[data-summary-shared] strong');
  const summaryComments = shell.querySelector('[data-summary-comments] strong');
  const summaryPhotos = shell.querySelector('[data-summary-photos] strong');
  const summaryUpdated = shell.querySelector('[data-summary-updated] strong');

  noteElements.forEach((el) => {
    try {
      const raw = el.getAttribute('data-note') || '{}';
      const data = JSON.parse(raw);
      if (data && typeof data.id !== 'undefined') {
        noteState.set(String(data.id), data);
      }
    } catch (err) {
      console.warn('Failed to parse note payload', err);
    }
  });

  const noteClickHandler = (event) => {
    event.preventDefault();
    activateNote(event.currentTarget);
  };

  function safeParseCount(el) {
    if (!el) return 0;
    const raw = el.textContent || '';
    const normalized = raw.replace(/[^0-9.-]/g, '');
    const value = parseInt(normalized, 10);
    return Number.isNaN(value) ? 0 : value;
  }

  function setCount(el, value) {
    if (!el) return;
    const safeValue = Number(value);
    el.textContent = Number.isNaN(safeValue) ? '0' : safeValue.toLocaleString();
  }

  function updateSummaryAfterCreate(payload) {
    if (summaryTotal) {
      setCount(summaryTotal, safeParseCount(summaryTotal) + 1);
    }
    if (summaryShared && Array.isArray(payload.shareIds) && payload.shareIds.length) {
      setCount(summaryShared, safeParseCount(summaryShared) + 1);
    }
    if (summaryComments && Number(payload.commentCount || 0) > 0) {
      setCount(summaryComments, safeParseCount(summaryComments) + Number(payload.commentCount || 0));
    }
    if (summaryPhotos && Number(payload.photoCount || 0) > 0) {
      setCount(summaryPhotos, safeParseCount(summaryPhotos) + Number(payload.photoCount || 0));
    }
    if (summaryUpdated) {
      summaryUpdated.textContent = 'just now';
    }
  }

  function todayIso() {
    const now = new Date();
    now.setMinutes(now.getMinutes() - now.getTimezoneOffset());
    return now.toISOString().slice(0, 10);
  }

  const preview = document.querySelector('[data-note-preview]');
  const previewRoot = preview ? preview.querySelector('[data-active-note-id]') : null;
  const previewTitle = preview ? preview.querySelector('[data-preview-title]') : null;
  const previewStatus = preview ? preview.querySelector('[data-preview-status]') : null;
  const previewIcon = preview ? preview.querySelector('[data-preview-icon]') : null;
  const previewOwner = preview ? preview.querySelector('[data-preview-owner]') : null;
  const previewPhotos = preview ? preview.querySelector('[data-preview-photos]') : null;
  const previewComments = preview ? preview.querySelector('[data-preview-comments]') : null;
  const previewDate = preview ? preview.querySelector('[data-preview-date]') : null;
  const previewProperties = preview ? preview.querySelector('[data-preview-properties]') : null;
  const previewTags = preview ? preview.querySelector('[data-preview-tags]') : null;
  const previewShares = preview ? preview.querySelector('[data-preview-shares]') : null;
  const previewView = preview ? preview.querySelector('[data-preview-view]') : null;
  const previewEdit = preview ? preview.querySelector('[data-preview-edit]') : null;
  const previewShareBtn = preview ? preview.querySelector('[data-open-note-share]') : null;

  function renderPreview(payload) {
    if (!payload || !preview) return;
    if (previewRoot) previewRoot.setAttribute('data-active-note-id', String(payload.id || ''));
    if (previewTitle) previewTitle.textContent = payload.title || 'Untitled';
    if (previewStatus) {
      previewStatus.textContent = payload.statusLabel || '';
      previewStatus.className = 'badge ' + (payload.statusBadge || 'badge--indigo');
    }
    if (previewIcon) previewIcon.textContent = payload.icon || '📝';
    if (previewOwner) previewOwner.textContent = payload.ownerLabel || '';
    if (previewPhotos) previewPhotos.textContent = String(payload.photoCount || 0);
    if (previewComments) previewComments.textContent = String(payload.commentCount || 0);
    if (previewDate) previewDate.textContent = payload.noteDate || '';
    if (previewView && payload.links) previewView.setAttribute('href', payload.links.view || '#');
    if (previewEdit && payload.links) previewEdit.setAttribute('href', payload.links.edit || '#');
    if (previewShareBtn) {
      if (payload.canShare) {
        previewShareBtn.dataset.openNoteShare = String(payload.id || '');
        previewShareBtn.style.display = '';
      } else {
        previewShareBtn.style.display = 'none';
      }
    }
    if (previewProperties) {
      previewProperties.innerHTML = '';
      if (payload.properties) {
        Object.entries(payload.properties).forEach(([key, value]) => {
          const val = String(value || '').trim();
          if (!val) return;
          const row = document.createElement('div');
          const label = document.createElement('span');
          label.textContent = key.replace(/_/g, ' ');
          const strong = document.createElement('strong');
          strong.textContent = val;
          row.appendChild(label);
          row.appendChild(strong);
          previewProperties.appendChild(row);
        });
      }
    }
    if (previewTags) {
      previewTags.innerHTML = '';
      (payload.tags || []).forEach((tag) => {
        const span = document.createElement('span');
        span.className = 'obsidian-tag';
        if (tag && tag.color) span.style.setProperty('--tag-color', tag.color);
        span.textContent = tag && tag.label ? tag.label : '';
        previewTags.appendChild(span);
      });
    }
    if (previewShares) {
      previewShares.innerHTML = '';
      const shares = Array.isArray(payload.shareLabels) ? payload.shareLabels : [];
      if (!shares.length) {
        const pill = document.createElement('span');
        pill.className = 'obsidian-pill is-muted';
        pill.textContent = 'Private';
        previewShares.appendChild(pill);
      } else {
        shares.forEach((label) => {
          const pill = document.createElement('span');
          pill.className = 'obsidian-pill';
          pill.textContent = label;
          previewShares.appendChild(pill);
        });
      }
    }
  }

  function activateNote(el) {
    if (!el) return;
    noteElements.forEach((node) => node.classList.remove('is-active'));
    el.classList.add('is-active');
    const noteId = el.getAttribute('data-note-id');
    const payload = noteState.get(String(noteId));
    if (payload) renderPreview(payload);
  }

  noteElements.forEach((el) => {
    el.addEventListener('click', noteClickHandler);
  });

  const initial = noteElements.find((el) => el.classList.contains('is-active'));
  if (initial) activateNote(initial);

  function normalizeIcon(payload) {
    if (payload && typeof payload.icon === 'string' && payload.icon.trim() !== '') {
      return payload.icon.trim();
    }
    const title = String((payload && payload.title) || '').trim();
    if (!title) return '📝';
    const first = title.charAt(0);
    return first ? first.toUpperCase() : '📝';
  }

  function createNoteCard(payload) {
    if (!payload) return null;
    const article = document.createElement('article');
    article.className = 'obsidian-note';
    article.dataset.noteId = String(payload.id || '');
    article.dataset.note = JSON.stringify(payload);
    article.setAttribute('data-note-item', '');

    const header = document.createElement('header');
    header.className = 'obsidian-note__header';

    const icon = document.createElement('span');
    icon.className = 'obsidian-note__icon';
    icon.textContent = normalizeIcon(payload);
    header.appendChild(icon);

    const titles = document.createElement('div');
    titles.className = 'obsidian-note__titles';
    const heading = document.createElement('h3');
    heading.textContent = payload.title || 'Untitled';
    titles.appendChild(heading);

    const meta = document.createElement('div');
    meta.className = 'obsidian-note__meta';
    const status = document.createElement('span');
    status.className = 'badge ' + (payload.statusBadge || 'badge--indigo');
    status.textContent = payload.statusLabel || '';
    meta.appendChild(status);
    if (payload.updatedRelative) {
      const timestamp = document.createElement('span');
      timestamp.className = 'obsidian-note__timestamp';
      timestamp.textContent = payload.updatedRelative;
      meta.appendChild(timestamp);
    }
    if (payload.isShared) {
      const shared = document.createElement('span');
      shared.className = 'obsidian-note__shared';
      shared.textContent = 'Shared';
      meta.appendChild(shared);
    }
    titles.appendChild(meta);
    header.appendChild(titles);

    const counts = document.createElement('div');
    counts.className = 'obsidian-note__counts';
    const sharePill = document.createElement('span');
    sharePill.className = 'obsidian-note__pill';
    sharePill.dataset.noteShareCount = String(payload.id || '');
    sharePill.textContent = `👥 ${Number(payload.shareCount || 0)}`;
    counts.appendChild(sharePill);
    const commentPill = document.createElement('span');
    commentPill.className = 'obsidian-note__pill';
    commentPill.textContent = `💬 ${Number(payload.commentCount || 0)}`;
    counts.appendChild(commentPill);
    const photoPill = document.createElement('span');
    photoPill.className = 'obsidian-note__pill';
    photoPill.textContent = `📸 ${Number(payload.photoCount || 0)}`;
    counts.appendChild(photoPill);
    header.appendChild(counts);

    article.appendChild(header);

    if (payload.excerpt) {
      const excerpt = document.createElement('p');
      excerpt.className = 'obsidian-note__excerpt';
      excerpt.textContent = payload.excerpt;
      article.appendChild(excerpt);
    }

    if (Array.isArray(payload.tags) && payload.tags.length) {
      const tagWrap = document.createElement('div');
      tagWrap.className = 'obsidian-note__tags';
      payload.tags.forEach((tag) => {
        if (!tag) return;
        const span = document.createElement('span');
        span.className = 'obsidian-tag';
        if (tag.color) {
          span.style.setProperty('--tag-color', tag.color);
        }
        span.textContent = tag.label || '';
        tagWrap.appendChild(span);
      });
      article.appendChild(tagWrap);
    }

    return article;
  }

  function insertNoteCard(payload) {
    if (!payload || !noteList) return;
    const card = createNoteCard(payload);
    if (!card) return;
    const emptyState = noteList.querySelector('.obsidian-empty');
    if (emptyState) emptyState.remove();
    noteList.prepend(card);
    card.addEventListener('click', noteClickHandler);
    noteElements.unshift(card);
    noteState.set(String(payload.id || ''), payload);
    activateNote(card);
  }

  const quickModal = document.getElementById('quickCaptureModal');
  const quickForm = quickModal ? quickModal.querySelector('[data-quick-form]') : null;
  const quickStatus = quickModal ? quickModal.querySelector('[data-quick-status]') : null;
  const quickDateInput = quickForm ? quickForm.querySelector('input[name="note_date"]') : null;
  const quickOpenToggle = quickForm ? quickForm.querySelector('input[name="quick_open"]') : null;
  const quickTitleInput = quickForm ? quickForm.querySelector('input[name="title"]') : null;
  const quickOpeners = document.querySelectorAll('[data-quick-open]');

  function resetQuickForm(preserveStatus = false) {
    if (!quickForm) return;
    quickForm.reset();
    if (quickDateInput) quickDateInput.value = todayIso();
    if (quickOpenToggle) quickOpenToggle.checked = true;
    if (!preserveStatus && quickStatus) {
      quickStatus.textContent = '';
      quickStatus.classList.remove('is-error');
      quickStatus.classList.remove('is-success');
    }
  }

  if (quickDateInput && !quickDateInput.value) {
    quickDateInput.value = todayIso();
  }

  function openQuickCapture() {
    if (!quickModal) return;
    resetQuickForm();
    openModal(quickModal);
    setTimeout(() => {
      if (quickTitleInput) quickTitleInput.focus();
    }, 10);
  }

  quickOpeners.forEach((btn) => {
    btn.addEventListener('click', (event) => {
      event.preventDefault();
      openQuickCapture();
    });
  });

  document.addEventListener('keydown', (event) => {
    const targetTag = (event.target && event.target.tagName ? event.target.tagName.toLowerCase() : '');
    if (targetTag === 'input' || targetTag === 'textarea') return;
    if ((event.metaKey || event.ctrlKey) && event.shiftKey && event.key.toLowerCase() === 'n') {
      event.preventDefault();
      openQuickCapture();
    }
  });

  if (quickForm) {
    quickForm.addEventListener('submit', (event) => {
      event.preventDefault();
      const formData = new FormData(quickForm);
      if (quickStatus) {
        quickStatus.textContent = 'Capturing…';
        quickStatus.classList.remove('is-error');
        quickStatus.classList.remove('is-success');
      }
      fetch('index.php', {
        method: 'POST',
        body: formData,
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
      }).then((res) => {
        if (res.ok) return res.json();
        return res.json().then((data) => {
          const message = data && data.message ? data.message : 'Request failed';
          throw new Error(message);
        }).catch(() => {
          throw new Error('Request failed');
        });
      }).then((data) => {
        if (!data || !data.ok || !data.note) {
          throw new Error((data && data.message) || 'Unable to capture note.');
        }
        const payload = data.note;
        insertNoteCard(payload);
        updateSummaryAfterCreate(payload);
        if (quickStatus) {
          quickStatus.textContent = 'Note captured';
          quickStatus.classList.add('is-success');
        }
        const shouldOpen = formData.get('quick_open') === '1';
        if (shouldOpen && payload.links && payload.links.edit) {
          window.location.href = payload.links.edit;
          return;
        }
        setTimeout(() => {
          closeModal(quickModal);
          resetQuickForm();
        }, 220);
      }).catch((error) => {
        if (quickStatus) {
          quickStatus.textContent = error && error.message ? error.message : 'Unable to capture note.';
          quickStatus.classList.add('is-error');
        }
      });
    });
  }

  const commandModal = document.getElementById('commandPalette');
  const commandInput = commandModal ? commandModal.querySelector('[data-command-input]') : null;
  const commandResults = commandModal ? commandModal.querySelector('[data-command-results]') : null;
  let commandItems = [];
  let commandIndex = -1;

  function closeModal(modal) {
    if (!modal) return;
    modal.classList.add('hidden');
    modal.setAttribute('aria-hidden', 'true');
  }

  function openModal(modal) {
    if (!modal) return;
    modal.classList.remove('hidden');
    modal.setAttribute('aria-hidden', 'false');
  }

  function openCommandPalette() {
    if (!commandModal || !commandResults) return;
    commandResults.innerHTML = '';
    commandItems = Array.from(noteState.values()).sort((a, b) => String(a.title || '').localeCompare(String(b.title || '')));
    commandItems.forEach((item, idx) => {
      const li = document.createElement('li');
      li.textContent = item.title || 'Untitled';
      li.dataset.noteId = String(item.id || '');
      if (!idx) {
        li.classList.add('is-active');
        commandIndex = 0;
      }
      li.addEventListener('click', () => {
        closeModal(commandModal);
        setTimeout(() => {
          const target = shell.querySelector(`[data-note-id="${item.id}"]`);
          if (target) {
            target.scrollIntoView({ behavior: 'smooth', block: 'center' });
            activateNote(target);
          }
        }, 40);
      });
      commandResults.appendChild(li);
    });
    openModal(commandModal);
    if (commandInput) {
      commandInput.value = '';
      commandInput.focus();
    }
  }

  document.addEventListener('keydown', (event) => {
    if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k') {
      event.preventDefault();
      openCommandPalette();
    }
    if (event.key === 'Escape') {
      closeModal(commandModal);
      closeModal(noteShareModal);
      closeModal(templateShareModal);
    }
  });

  if (commandModal && commandResults && commandInput) {
    commandInput.addEventListener('input', () => {
      const query = commandInput.value.toLowerCase();
      Array.from(commandResults.children).forEach((li, idx) => {
        const match = li.textContent.toLowerCase().includes(query);
        li.style.display = match ? '' : 'none';
        if (match && commandIndex === -1) {
          commandIndex = idx;
          li.classList.add('is-active');
        }
      });
    });
    commandModal.addEventListener('keydown', (event) => {
      const visible = Array.from(commandResults.children).filter((li) => li.style.display !== 'none');
      if (!visible.length) return;
      if (event.key === 'ArrowDown') {
        event.preventDefault();
        commandIndex = (commandIndex + 1) % visible.length;
        visible.forEach((li, idx) => li.classList.toggle('is-active', idx === commandIndex));
      } else if (event.key === 'ArrowUp') {
        event.preventDefault();
        commandIndex = (commandIndex - 1 + visible.length) % visible.length;
        visible.forEach((li, idx) => li.classList.toggle('is-active', idx === commandIndex));
      } else if (event.key === 'Enter') {
        event.preventDefault();
        const target = visible[commandIndex];
        if (target) target.click();
      }
    });
  }

  const noteShareModal = document.getElementById('noteShareModal');
  const noteShareForm = noteShareModal ? noteShareModal.querySelector('[data-note-share-form]') : null;
  const noteShareStatus = noteShareModal ? noteShareModal.querySelector('[data-share-status]') : null;
  const noteShareTitle = noteShareModal ? noteShareModal.querySelector('[data-share-note-title]') : null;
  const noteShareOptions = noteShareModal ? Array.from(noteShareModal.querySelectorAll('[data-share-option]')) : [];

  function syncOptions(options, selected, ownerId) {
    options.forEach((option) => {
      const checkbox = option.querySelector('input[type="checkbox"]');
      if (!checkbox) return;
      const uid = Number(option.dataset.userId || '0');
      checkbox.checked = selected.includes(uid);
      if (uid === ownerId) {
        checkbox.checked = true;
        checkbox.disabled = true;
        option.classList.add('is-disabled');
      } else {
        checkbox.disabled = false;
        option.classList.remove('is-disabled');
      }
    });
  }

  function openNoteShare(noteId) {
    if (!noteShareModal) return;
    const payload = noteState.get(String(noteId));
    if (!payload) return;
    if (noteShareForm) {
      noteShareForm.querySelector('input[name="note_id"]').value = String(noteId);
    }
    if (noteShareTitle) noteShareTitle.textContent = payload.title || 'Untitled';
    syncOptions(noteShareOptions, payload.shareIds || [], Number(payload.ownerId || 0));
    if (noteShareStatus) {
      noteShareStatus.textContent = '';
      noteShareStatus.classList.remove('is-error');
    }
    openModal(noteShareModal);
    const first = noteShareModal.querySelector('input[type="checkbox"]');
    if (first) first.focus();
  }

  function updateNoteShares(noteId, shares) {
    const payload = noteState.get(String(noteId));
    if (!payload) return;
    const ids = shares.map((entry) => Number(entry.id || entry));
    const labels = shares.map((entry) => entry.label || String(entry.id));
    payload.shareIds = ids;
    payload.shareLabels = labels;
    payload.shareCount = ids.length;
    noteState.set(String(noteId), payload);
    const pill = document.querySelector(`[data-note-share-count="${noteId}"]`);
    if (pill) pill.textContent = `👥 ${ids.length}`;
    if (previewRoot && String(previewRoot.getAttribute('data-active-note-id')) === String(noteId)) {
      renderPreview(payload);
    }
  }

  if (noteShareForm) {
    noteShareForm.addEventListener('submit', (event) => {
      event.preventDefault();
      const formData = new FormData(noteShareForm);
      fetch('index.php', {
        method: 'POST',
        body: formData,
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
      }).then((res) => {
        if (!res.ok) throw new Error('Request failed');
        return res.json();
      }).then((data) => {
        if (data && data.ok) {
          updateNoteShares(data.note_id, data.shares || []);
          if (noteShareStatus) {
            noteShareStatus.textContent = 'Access updated';
            noteShareStatus.classList.remove('is-error');
          }
          setTimeout(() => closeModal(noteShareModal), 350);
        } else {
          throw new Error('Bad response');
        }
      }).catch(() => {
        if (noteShareStatus) {
          noteShareStatus.textContent = 'Could not update shares.';
          noteShareStatus.classList.add('is-error');
        }
      });
    });
  }

  document.querySelectorAll('[data-open-note-share]').forEach((btn) => {
    btn.addEventListener('click', (event) => {
      event.preventDefault();
      openNoteShare(btn.getAttribute('data-open-note-share'));
    });
  });

  const templateShareModal = document.getElementById('templateShareModal');
  const templateShareForm = templateShareModal ? templateShareModal.querySelector('[data-template-share-form]') : null;
  const templateShareStatus = templateShareModal ? templateShareModal.querySelector('[data-template-share-status]') : null;
  const templateShareTitle = templateShareModal ? templateShareModal.querySelector('[data-share-template-title]') : null;
  const templateShareOptions = templateShareModal ? Array.from(templateShareModal.querySelectorAll('[data-template-share-option]')) : [];
  const templateState = new Map();
  const templateIdInput = templateShareForm ? templateShareForm.querySelector('input[name="template_id"]') : null;

  function applyTemplateState(templateId, payload) {
    if (!templateId) return {};
    const key = String(templateId);
    const existing = templateState.get(key) || {};
    const next = Object.assign({ id: Number(templateId) }, existing, payload || {});
    templateState.set(key, next);
    const trigger = document.querySelector(`[data-template-share-button="${key}"]`);
    if (trigger) {
      try {
        trigger.setAttribute('data-template-share', JSON.stringify(next));
      } catch (err) {
        // ignore serialization issues
      }
    }
    return next;
  }

  function renderTemplateShares(templateId, entries) {
    const list = document.querySelector(`[data-template-share-list="${templateId}"]`);
    if (!list) return;
    list.innerHTML = '';
    if (!entries || !entries.length) {
      const pill = document.createElement('span');
      pill.className = 'obsidian-pill is-muted';
      pill.textContent = 'Private';
      list.appendChild(pill);
      return;
    }
    entries.forEach((entry) => {
      const pill = document.createElement('span');
      pill.className = 'obsidian-pill';
      pill.textContent = entry.label || entry;
      list.appendChild(pill);
    });
  }

  function syncTemplateOptions(state) {
    const selected = Array.isArray(state.shareIds) ? state.shareIds.map(Number) : [];
    const ownerId = Number(state.ownerId || 0);
    templateShareOptions.forEach((option) => {
      const checkbox = option.querySelector('input[type="checkbox"]');
      if (!checkbox) return;
      const uid = Number(option.dataset.userId || '0');
      const isOwner = ownerId > 0 && uid === ownerId;
      checkbox.checked = isOwner || selected.includes(uid);
      checkbox.disabled = isOwner;
      option.classList.toggle('is-disabled', isOwner);
    });
  }

  if (templateShareModal) {
    document.querySelectorAll('[data-template-share]').forEach((button) => {
      const raw = button.getAttribute('data-template-share') || '{}';
      let parsed = {};
      try {
        parsed = JSON.parse(raw);
      } catch (err) {
        console.warn('Template payload error', err);
      }
      const templateId = button.getAttribute('data-template-share-button') || String(parsed.id || '');
      if (templateId) {
        applyTemplateState(templateId, parsed);
      }
      button.addEventListener('click', (event) => {
        event.preventDefault();
        if (!templateShareForm || !templateIdInput) return;
        const key = String(templateId || '');
        const state = templateState.get(key) || applyTemplateState(key, parsed);
        templateIdInput.value = key;
        if (templateShareTitle) templateShareTitle.textContent = state.name || 'Template';
        syncTemplateOptions(state);
        if (templateShareStatus) {
          templateShareStatus.textContent = '';
          templateShareStatus.classList.remove('is-error');
        }
        openModal(templateShareModal);
        const first = templateShareModal.querySelector('input[type="checkbox"]');
        if (first) first.focus();
      });
    });

    if (templateShareForm) {
      templateShareForm.addEventListener('submit', (event) => {
        event.preventDefault();
        const formData = new FormData(templateShareForm);
        fetch('index.php', {
          method: 'POST',
          body: formData,
          headers: { 'X-Requested-With': 'XMLHttpRequest' },
        }).then((res) => {
          if (!res.ok) throw new Error('Request failed');
          return res.json();
        }).then((data) => {
          if (data && data.ok) {
            const templateId = String(data.template_id || formData.get('template_id') || '');
            const shares = Array.isArray(data.shares) ? data.shares : [];
            const normalized = shares.map((entry) => ({
              id: Number(entry.id || entry),
              label: entry.label || String(entry.id || ''),
            })).filter((entry) => entry.label !== '' || entry.id > 0);
            const shareIds = normalized.map((entry) => entry.id).filter((id) => id > 0);
            const shareLabels = normalized.map((entry) => entry.label);
            const state = applyTemplateState(templateId, { shareIds, shareLabels });
            renderTemplateShares(templateId, normalized);
            syncTemplateOptions(state);
            if (templateShareStatus) {
              templateShareStatus.textContent = 'Template access updated';
              templateShareStatus.classList.remove('is-error');
            }
            setTimeout(() => closeModal(templateShareModal), 350);
          } else {
            throw new Error('Bad response');
          }
        }).catch(() => {
          if (templateShareStatus) {
            templateShareStatus.textContent = 'Could not update template shares.';
            templateShareStatus.classList.add('is-error');
          }
        });
      });
    }
  }

document.querySelectorAll('[data-modal-close]').forEach((btn) => {
    btn.addEventListener('click', (event) => {
      event.preventDefault();
      closeModal(btn.closest('[data-modal]'));
    });
  });
})();
</script>
<?php include __DIR__ . '/../includes/footer.php';
