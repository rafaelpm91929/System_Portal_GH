<?php
require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/permisos_helper.php';

asegurarTablasInfraestructura($pdo);

$stmt = $pdo->query("SELECT COUNT(*) FROM infra_vlans");
$count = $stmt ? intval($stmt->fetchColumn()) : 0;

if ($count === 0) {
    $sql = "INSERT INTO infra_vlans (vlan_id, nombre_vlan, subred, gateway, dhcp_rango, descripcion, estatus) VALUES 
        (10, 'VLAN_DATOS_CORP', '192.168.10.0/24', '192.168.10.1', '192.168.10.100 - 192.168.10.250', 'Red principal de datos para equipos de cómputo de la agencia.', 'Activa'),
        (20, 'VLAN_VOIP_TELEFONIA', '192.168.20.0/24', '192.168.20.1', '192.168.20.100 - 192.168.20.200', 'Segmento exclusivo con prioridad QoS para telefonía e IP-PBX.', 'Activa'),
        (30, 'VLAN_CCTV_SEGURIDAD', '192.168.30.0/24', '192.168.30.1', '192.168.30.50 - 192.168.30.150', 'Segmento dedicado para grabadores DVR/NVR y cámaras IP.', 'Activa'),
        (100, 'VLAN_WIFI_INVITADOS', '172.16.100.0/22', '172.16.100.1', '172.16.100.10 - 172.16.103.250', 'Red aislada para visitantes con restricción a servidores.', 'Activa')";
    $pdo->exec($sql);
    echo "4 VLANs cargadas correctamente.";
} else {
    echo "Ya existen " . $count . " VLANs en la base de datos.";
}
