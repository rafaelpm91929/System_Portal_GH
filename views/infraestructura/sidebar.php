<!-- BACKDROP OVERLAY PARA CELULARES / MÓVILES -->
<div id="sidebarBackdrop" class="sidebar-backdrop" onclick="cerrarSidebarMobile()"></div>

<!-- MENÚ LATERAL DE NAVEGACIÓN (SIDEBAR) -->
<div id="colSidebarNavegacion" class="col-md-3 col-lg-2 sidebar-wrapper">
    <div class="d-flex justify-content-between align-items-center mb-3 px-1 pb-2 border-bottom border-secondary border-opacity-25">
        <div class="sidebar-section-title mb-0 p-0 text-info fw-bold">SECCIONES PRINCIPALES</div>
        <button type="button" class="btn btn-sm btn-outline-secondary text-white p-1 px-2 border-0 rounded-2" onclick="toggleSidebarNavegacion()" title="Cerrar / Ocultar Menú Lateral">
            <i class="bi bi-x-lg fs-5 text-white d-md-none" id="iconCloseSidebarMobile"></i>
            <i class="bi bi-chevron-left fs-6 text-white d-none d-md-inline" id="iconToggleSidebar"></i>
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
