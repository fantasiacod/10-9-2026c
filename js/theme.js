(function() {
    function applyImmediateTheme() {
        try {
            // Prefer the server-rendered data when present: it is
            // regenerated on every request, so unlike localStorage it
            // can never be a stale copy left over on the device.
            const ssr = (typeof window !== 'undefined' && window.__SITE_DATA__ && window.__SITE_DATA__.config)
                ? JSON.stringify(window.__SITE_DATA__.config) : null;
            const configStr = ssr || localStorage.getItem('siteConfig_v2');
            let config = {
                decoration: 'islamic-1',
                decorationColor: '#ffffff',
                decorationOpacity: '0.15',
                colorPrimary: '#caaa98',
                colorBg: '#4b4038',
                hfColor1: '#202940',
                hfColor2: '#4b4038',
                textColor: '#ffffff',
                cardTextColor: '#ffffff',
                btnTextColor: '#202940'
            };
            if (configStr) {
                try {
                    config = { ...config, ...JSON.parse(configStr) };
                } catch(e) {}
            }
            const root = document.documentElement;
            
            if (config.colorPrimary) root.style.setProperty('--primary', config.colorPrimary);
            if (config.btnTextColor) root.style.setProperty('--btn-text-color', config.btnTextColor);
            if (config.colorBg) root.style.setProperty('--bg-color', config.colorBg);
            if (config.textColor) {
                root.style.setProperty('--text-color', config.textColor);
                root.style.setProperty('--card-text-color', config.textColor);
                root.style.setProperty('--text-main', config.textColor);
                root.style.setProperty('--text-muted', config.textColor);
            }


            
            if (config.hfColor1) root.style.setProperty('--hf-bg-color1', config.hfColor1);
            if (config.hfColor2) root.style.setProperty('--hf-bg-color2', config.hfColor2);
            
            const dec = config.decoration || config.hfDecoration || 'islamic-1';
            const decColor = config.decorationColor || config.hfDecorationColor || '#ffffff';
            const decOpacity = config.decorationOpacity || config.hfOpacity || '0.15';
            
            root.style.setProperty('--decoration-color', decColor);
            root.style.setProperty('--decoration-opacity', decOpacity);
            root.style.setProperty('--hf-decoration-color', decColor);
            root.style.setProperty('--hf-decoration-opacity', decOpacity);


            const applyOverlays = () => {
                if (config.fontFamily && document.body) {
                    document.body.style.fontFamily = config.fontFamily;
                }
                const overlays = document.querySelectorAll('.decoration-overlay, .hf-decoration-overlay');
                overlays.forEach(el => {
                    const isHf = el.classList.contains('hf-decoration-overlay');
                    const baseClass = isHf ? 'hf-decoration-overlay' : 'decoration-overlay';
                    if (dec && dec !== 'none') {
                        el.className = `${baseClass} pattern-${dec} active`;
                    } else {
                        el.className = baseClass;
                    }
                });
            };

            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', applyOverlays);
            } else {
                applyOverlays();
            }
        } catch (e) {
            // Ignore JSON parse errors
        }
    }
    applyImmediateTheme();
})();

