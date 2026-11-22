// MapLibre finds its worker next to its own bundle, which Vite renames, so point it at the emitted file.
import workerUrl from 'maplibre-gl/dist/maplibre-gl-worker.mjs?url';

const MAP_STYLE = 'https://tiles.openfreemap.org/styles/positron';

document.addEventListener('alpine:init', () => {
    window.Alpine.data('nswMap', (features, center, zoom) => {
        // Kept out of Alpine's reactive proxy: MapLibre breaks when wrapped.
        let map = null;

        return {
            async init() {
                const [{ Map, NavigationControl, setWorkerUrl }] = await Promise.all([
                    import('maplibre-gl'),
                    import('maplibre-gl/dist/maplibre-gl.css'),
                ]);

                setWorkerUrl(workerUrl);
                map = new Map({ container: this.$refs.canvas, style: MAP_STYLE, center, zoom });
                map.addControl(new NavigationControl(), 'top-right');
                map.on('load', () => {
                    map.addSource('projects', { type: 'geojson', data: features });
                    map.addLayer({
                        id: 'projects',
                        type: 'circle',
                        source: 'projects',
                        paint: {
                            'circle-radius': 5,
                            'circle-color': '#2563eb',
                            'circle-stroke-width': 1,
                            'circle-stroke-color': '#ffffff',
                        },
                    });
                });
            },

            setFeatures(collection) {
                features = collection; // picked up by the load handler if the style is still loading
                map?.getSource('projects')?.setData(collection);
            },

            destroy() {
                map?.remove();
                map = null;
            },
        };
    });
});
