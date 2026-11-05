-- ============================================================================
-- Toma física sin lote - tablas de bitácora
-- Ejecutar en la base de datos WEB (la misma donde están pedidos, usuarios, ...)
-- NO en dms_farmaofertas.
-- ============================================================================

IF OBJECT_ID('dbo.toma_fisica_ajustes', 'U') IS NULL
BEGIN
  CREATE TABLE [dbo].[toma_fisica_ajustes] (
    [id]                  int IDENTITY(1, 1) NOT NULL,
    [empresa_id]          int NOT NULL,
    [bodega]              smallint NOT NULL,
    [toma]                tinyint NOT NULL,
    [usuario_id]          int NOT NULL,
    [usuario]             varchar(10) NULL,
    [fecha]               datetime NOT NULL CONSTRAINT [DF_toma_fisica_ajustes_fecha] DEFAULT getdate(),
    [tipo_entrada]        varchar(4) NULL,
    [numero_entrada]      int NULL,
    [tipo_salida]         varchar(4) NULL,
    [numero_salida]       int NULL,
    [lineas_entrada]      int NOT NULL DEFAULT 0,
    [lineas_salida]       int NOT NULL DEFAULT 0,
    [incluyo_no_contados] bit NOT NULL DEFAULT 0,
    [notas]               varchar(400) NULL,
    CONSTRAINT [PK_toma_fisica_ajustes] PRIMARY KEY CLUSTERED ([id])
  );
  CREATE NONCLUSTERED INDEX [IX_toma_fisica_ajustes_bodega] ON [dbo].[toma_fisica_ajustes] ([empresa_id], [bodega], [id]);
END
GO

IF OBJECT_ID('dbo.toma_fisica_ajustes_det', 'U') IS NULL
BEGIN
  CREATE TABLE [dbo].[toma_fisica_ajustes_det] (
    [id]             int IDENTITY(1, 1) NOT NULL,
    [ajuste_id]      int NOT NULL,
    [codigo]         varchar(20) NOT NULL,
    [id_lote]        int NOT NULL,
    [lote]           varchar(30) NULL,
    [stock_sistema]  decimal(24, 4) NULL,
    [cantidad_final] decimal(24, 4) NULL,
    [diferencia]     decimal(24, 4) NULL,
    [sw]             tinyint NOT NULL,
    [tipo]           varchar(4) NOT NULL,
    [numero]         int NOT NULL,
    CONSTRAINT [PK_toma_fisica_ajustes_det] PRIMARY KEY CLUSTERED ([id]),
    CONSTRAINT [FK_toma_fisica_ajustes_det] FOREIGN KEY ([ajuste_id]) REFERENCES [dbo].[toma_fisica_ajustes] ([id])
  );
  CREATE NONCLUSTERED INDEX [IX_toma_fisica_ajustes_det_ajuste] ON [dbo].[toma_fisica_ajustes_det] ([ajuste_id]);
END
GO
