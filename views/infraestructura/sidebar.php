<!-- MENÚ LATERAL DE NAVEGACIÓN (SIDEBAR) -->
<div id="colSidebarNavegacion" class="col-md-3 col-lg-2 sidebar-wrapper">
    <div class="d-flex justify-content-between align-items-center mb-2 px-1">
        <div class="sidebar-section-title mb-0 p-0">SECCIONES PRINCIPALES</div>
        <button type="button" class="btn btn-sm text-secondary p-0 border-0" onclick="toggleSidebarNavegacion()" title="Ocultar Menú Lateral">
            <i class="bi bi-chevron-left fs-6 text-white" id="iconToggleSidebar"></i>
        </button>
    </div>
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
