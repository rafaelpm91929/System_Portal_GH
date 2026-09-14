-- =============================================
-- SCRIPT DE INICIALIZACIÓN - DATABASE SYSTEMS_PORTAL
-- =============================================

USE systems_portal;
GO

IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'Users')
BEGIN
    CREATE TABLE Users (
        id INT IDENTITY(1,1) PRIMARY KEY,
        name NVARCHAR(100) NOT NULL,
        email NVARCHAR(100) NOT NULL UNIQUE,
        password NVARCHAR(255) NOT NULL,
        role NVARCHAR(50) NOT NULL DEFAULT 'Admin',
        active BIT DEFAULT 1,
        createdAt DATETIME DEFAULT GETDATE(),
        updatedAt DATETIME DEFAULT GETDATE()
    );
END
GO
