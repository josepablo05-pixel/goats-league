<?php
require_once __DIR__ . '/db.php';

// Build full standings from finished matches
$rawTeams = $pdo->query("SELECT id, name, points FROM teams ORDER BY name")->fetchAll();

$teams = [];
foreach ($rawTeams as $t) {
    $tid = $t['id'];

    // Matches where this team played as team1
    $s1 = $pdo->prepare("
        SELECT team1_score AS gf, team2_score AS gc
        FROM matches WHERE team1_id = ? AND status = 'finished'
    ");
    $s1->execute([$tid]);
    $as_team1 = $s1->fetchAll();

    // Matches where this team played as team2
    $s2 = $pdo->prepare("
        SELECT team2_score AS gf, team1_score AS gc
        FROM matches WHERE team2_id = ? AND status = 'finished'
    ");
    $s2->execute([$tid]);
    $as_team2 = $s2->fetchAll();

    $all = array_merge($as_team1, $as_team2);
    $pj = count($all);
    $v = $e = $d = $gf = $gc = 0;

    foreach ($all as $m) {
        $gf += (int)$m['gf'];
        $gc += (int)$m['gc'];
        if ($m['gf'] > $m['gc'])      $v++;
        elseif ($m['gf'] === $m['gc']) $e++;
        else                           $d++;
    }

    $calc_points = ($v * 3) + ($e * 1);

    $teams[] = [
        'id'     => $tid,
        'name'   => $t['name'],
        'points' => $calc_points,
        'pj'     => $pj,
        'v'      => $v,
        'e'      => $e,
        'd'      => $d,
        'gf'     => $gf,
        'gc'     => $gc,
        'dg'     => $gf - $gc,
    ];
}

// Prepare all finished matches for H2H calculations
$allMatchesRaw = $pdo->query("SELECT team1_id, team2_id, team1_score, team2_score FROM matches WHERE status='finished'")->fetchAll();

// Group teams by points
$groups = [];
foreach ($teams as $t) {
    $groups[$t['points']][] = $t;
}

// Sort points descending to process buckets
krsort($groups);

$sortedTeams = [];
foreach ($groups as $pts => $group) {
    if (count($group) > 1) {
        $groupIds = array_column($group, 'id');
        $miniStats = [];
        foreach ($groupIds as $id) {
            $miniStats[$id] = ['pts' => 0, 'dg' => 0];
        }

        foreach ($allMatchesRaw as $m) {
            if (in_array($m['team1_id'], $groupIds) && in_array($m['team2_id'], $groupIds)) {
                if ($m['team1_score'] > $m['team2_score']) {
                    $miniStats[$m['team1_id']]['pts'] += 3;
                } elseif ($m['team1_score'] < $m['team2_score']) {
                    $miniStats[$m['team2_id']]['pts'] += 3;
                } else {
                    $miniStats[$m['team1_id']]['pts'] += 1;
                    $miniStats[$m['team2_id']]['pts'] += 1;
                }
                $miniStats[$m['team1_id']]['dg'] += ($m['team1_score'] - $m['team2_score']);
                $miniStats[$m['team2_id']]['dg'] += ($m['team2_score'] - $m['team1_score']);
            }
        }

        usort($group, function($a, $b) use ($miniStats) {
            // 1. Mini-league points
            $ptsDiff = $miniStats[$b['id']]['pts'] - $miniStats[$a['id']]['pts'];
            if ($ptsDiff !== 0) return $ptsDiff;
            
            // 2. Mini-league goal difference
            $dgDiff = $miniStats[$b['id']]['dg'] - $miniStats[$a['id']]['dg'];
            if ($dgDiff !== 0) return $dgDiff;
            
            // 3. General Goal Difference
            if ($b['dg'] !== $a['dg']) return $b['dg'] - $a['dg'];
            
            // 4. General Goals For
            return $b['gf'] - $a['gf'];
        });
    }
    
    foreach ($group as $t) {
        $sortedTeams[] = $t;
    }
}

$teams = $sortedTeams;
?>