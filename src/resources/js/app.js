import Alpine from 'alpinejs'
import 'leaflet/dist/leaflet.css'
import L from 'leaflet'
import 'leaflet.markercluster/dist/MarkerCluster.css'
import 'leaflet.markercluster/dist/MarkerCluster.Default.css'
import 'leaflet.markercluster'
import { computePosition, offset, flip, shift, autoUpdate } from '@floating-ui/dom'

// Leaflet accessible globalement (utilisé dans les vues Blade)
window.L = L
window.Alpine = Alpine

// Floating UI exposé via window.FloatingUI pour les dropdowns et tooltips
// flottants (gestion auto du flip/shift sur les bords du viewport, vs le
// calcul manuel getBoundingClientRect() actuel). Migration progressive
// depuis le pattern existant (`x-show` + `:style="top:Xpx;left:Ypx"`).
window.FloatingUI = { computePosition, offset, flip, shift, autoUpdate }

Alpine.start()
