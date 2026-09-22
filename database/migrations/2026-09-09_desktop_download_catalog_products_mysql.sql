-- Catálogo Downloads: alinhamento de default para novos pacotes View/Desktop no dialeto MySQL.
ALTER TABLE bi_desktop_release_packages
    MODIFY product_key VARCHAR(64) NOT NULL DEFAULT 'voxel_view_desktop';
