-- Catálogo Downloads: mantém o rascunho legado e permite separar View/Desktop de Router Desktop.
SET search_path TO voxelpacs_mysql_source;

DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'bi_desktop_release_packages_product_key_check') THEN
        ALTER TABLE bi_desktop_release_packages DROP CONSTRAINT bi_desktop_release_packages_product_key_check;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'bi_desktop_release_packages_product_key_allowed_check') THEN
        ALTER TABLE bi_desktop_release_packages
            ADD CONSTRAINT bi_desktop_release_packages_product_key_allowed_check
            CHECK (product_key IN ('voxel_desktop', 'voxel_view_desktop', 'voxel_router_desktop')) NOT VALID;
    END IF;
END $$;

ALTER TABLE bi_desktop_release_packages
    VALIDATE CONSTRAINT bi_desktop_release_packages_product_key_allowed_check;

ALTER TABLE bi_desktop_release_packages
    ALTER COLUMN product_key SET DEFAULT 'voxel_view_desktop';
