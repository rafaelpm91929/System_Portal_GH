<!-- MENÚ LATERAL DE NAVEGACIÓN (SIDEBAR) -->
<div id="colSidebarNavegacion" class="col-md-3 col-lg-2 sidebar-wrapper">
    <div class="sidebar-section-title">SECCIONES PRINCIPALES</div>
    <?php foreach ($SECCIONES as $key => $sec): ?>
        <a href="infraestructura.php?sec=<?php echo $key; ?>" 
           class="nav-link-custom <?php echo ($seccion_activa === $key) ? 'active' : ''; ?>">
            <div class="nav-icon-box">
                <i class="bi <?php echo $sec['icono']; ?>"></i>
            </div>
            <div>
                <div class="fw-bold" style="line-height: 1.2;"><?php echo htmlspecialchars($sec['nombre']); ?></div>
            </div>
        </a>
    <?php endforeach; ?>
</div>
