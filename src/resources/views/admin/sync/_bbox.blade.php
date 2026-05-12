{{-- Champs bbox réutilisés par les formulaires de découverte de balises.
     Valeurs par défaut : large (France + zones frontalières). --}}
@php
    $bboxInputCls = 'w-full mt-1 px-2 py-1.5 text-sm bg-gray-950 border border-gray-700 rounded text-gray-100 focus:outline-none focus:border-sky-500 focus:ring-1 focus:ring-sky-500/40';
@endphp
<div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
    <div>
        <label class="block text-xs uppercase tracking-wider text-gray-500">Lat min</label>
        <input type="number" step="0.01" name="lat_min" value="{{ old('lat_min', $bbox['lat_min']) }}" class="{{ $bboxInputCls }}">
    </div>
    <div>
        <label class="block text-xs uppercase tracking-wider text-gray-500">Lat max</label>
        <input type="number" step="0.01" name="lat_max" value="{{ old('lat_max', $bbox['lat_max']) }}" class="{{ $bboxInputCls }}">
    </div>
    <div>
        <label class="block text-xs uppercase tracking-wider text-gray-500">Lng min</label>
        <input type="number" step="0.01" name="lng_min" value="{{ old('lng_min', $bbox['lng_min']) }}" class="{{ $bboxInputCls }}">
    </div>
    <div>
        <label class="block text-xs uppercase tracking-wider text-gray-500">Lng max</label>
        <input type="number" step="0.01" name="lng_max" value="{{ old('lng_max', $bbox['lng_max']) }}" class="{{ $bboxInputCls }}">
    </div>
</div>
