import Alpine from 'alpinejs'
import 'leaflet/dist/leaflet.css'
import L from 'leaflet'

// Leaflet accessible globalement (utilisé dans les vues Blade)
window.L = L
window.Alpine = Alpine
Alpine.start()
