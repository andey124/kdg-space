<?php
require __DIR__ . '/private/config.php';
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Umfrage anlegen</title>
    <style>
        body { font-family: system-ui, sans-serif; margin: 2rem; max-width: 700px; }
        label { display: block; margin-top: 1rem; font-weight: 600; }
        input[type="text"], input[type="number"], input[type="email"], textarea {
            width: 100%; padding: 0.5rem; box-sizing: border-box;
        }
        button { margin-top: 1.5rem; padding: 0.6rem 1.2rem; cursor: pointer; }
        .hint { font-size: 0.9rem; color: #555; }
        .toplink { margin-bottom: 1rem; display: inline-block; }
    </style>
</head>
<body>
<p><a class="toplink" href="index.php">← Zur Übersicht</a></p>

<h1>Neue Umfrage anlegen</h1>

<form action="create_survey.php" method="post">
    <label for="question">Frage / Titel der Umfrage</label>
    <input type="text" id="question" name="question" required maxlength="255">

    <label for="choices">Antwortoptionen (eine pro Zeile)</label>
    <textarea id="choices" name="choices" rows="6" required></textarea>
    <div class="hint">Mindestens zwei Optionen, leere Zeilen werden ignoriert.</div>

    <label for="expected_votes">Erwartete Anzahl an Stimmen</label>
    <input type="number" id="expected_votes" name="expected_votes" min="1" required>

    <label for="email">Benachrichtigungs-E-Mail</label>
    <input type="email" id="email" name="email" required>

    <button type="submit">Umfrage erstellen</button>
</form>
</body>
</html>
