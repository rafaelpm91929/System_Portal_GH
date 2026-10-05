<?php
// Redirige al módulo de tickets con el ticket seleccionado en la misma pestaña activa
$id = intval($_GET['id'] ?? ($_GET['ver'] ?? 0));
header("Location: tickets.php?ver=" . $id);
exit;
