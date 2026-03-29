<?php
require __DIR__ . '/private/config.php';

$now = new DateTimeImmutable('now');

$stmt = $pdo->query("
    SELECT
        s.id,
        s.public_id,
        s.question,
        s.expected_votes,
        s.created_at,
        s.expires_at,
        COUNT(v.id) AS total_votes
    FROM surveys s
    LEFT JOIN votes v ON v.survey_id = s.id
    WHERE s.expires_at > NOW()
    GROUP BY s.id, s.public_id, s.question, s.expected_votes, s.created_at, s.expires_at
    ORDER BY s.created_at DESC
");

$surveys = $stmt->fetchAll();

function formatRemainingTime(string $expiresAtRaw): string {
    $now = new DateTimeImmutable('now');
    $expiresAt = new DateTimeImmutable($expiresAtRaw);

    if ($now >= $expiresAt) {
        return 'abgelaufen';
    }

    $diff = $now->diff($expiresAt);

    if ($diff->days > 0) {
        return sprintf('%d Tage, %d Std.', $diff->days, $diff->h);
    }

    if ($diff->h > 0) {
        return sprintf('%d Std., %d Min.', $diff->h, $diff->i);
    }

    return sprintf('%d Min.', max(1, $diff->i));
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Aktive Umfragen</title>
    <style>
        body { font-family: system-ui, sans-serif; margin: 2rem; max-width: 1000px; }
        .topbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 1rem;
            margin-bottom: 2rem;
            flex-wrap: wrap;
        }
        .button {
            display: inline-block;
            padding: 0.65rem 1rem;
            background: #111;
            color: #fff;
            text-decoration: none;
            border-radius: 8px;
        }
        table {
            border-collapse: collapse;
            width: 100%;
        }
        th, td {
            border: 1px solid #ddd;
            padding: 0.75rem;
            text-align: left;
            vertical-align: top;
        }
        th { background: #f5f5f5; }
        .muted { color: #666; font-size: 0.95rem; }
    </style>
</head>
<body>
    <div class="topbar">
        <div>
            <h1>Aktive Umfragen</h1>
            <div class="muted">Öffentliche Übersicht laufender Abstimmungen</div>
        </div>
        <a class="button" href="create.php">Neue Umfrage anlegen</a>
    </div>

    <?php if (!$surveys): ?>
        <p>Aktuell gibt es keine laufenden Umfragen.</p>
    <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>Umfrage</th>
                    <th>Stimmen</th>
                    <th>Ziel</th>
                    <th>Verbleibende Zeit</th>
                    <th>Öffnen</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($surveys as $survey): ?>
                    <tr>
                        <td>
                            <?php echo htmlspecialchars($survey['question']); ?>
                            <div class="muted">
                                gestartet: <?php echo htmlspecialchars($survey['created_at']); ?>
                            </div>
                        </td>
                        <td><?php echo (int)$survey['total_votes']; ?></td>
                        <td><?php echo (int)$survey['expected_votes']; ?></td>
                        <td><?php echo htmlspecialchars(formatRemainingTime($survey['expires_at'])); ?></td>
                        <td>
                            <a href="survey.php?sid=<?php echo urlencode($survey['public_id']); ?>">
                                Zur Umfrage
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</body>
</html>
