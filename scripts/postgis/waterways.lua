-- osm2pgsql (Flex-Ausgabe): Geodaten für Swiftwater Rescue
--
-- 1. sw_waterways (Issue #131): Gewässernetz, nur Wege mit waterway=river|stream|canal, wie die Overpass-Abfrage der App
--    (overpassQuery in web/api/index.php). Spalten id (OSM-Weg), waterway, name, geom (LineString, SRID 4326).
--    Knoten braucht die App nicht: Sie verbindet Wege über gleiche Koordinaten (pgWays), die an Zusammenflüssen vom selben OSM-Knoten stammen.
--
-- 2. sw_features (Issue #182): Objekte am Gewässer für die Vorbereitungsansicht und die Geodaten-Ebene
--    (Wehre, Kraftwerke, Brücken, Slipanlagen …). Spalten osm_type (N/W/R), osm_id, kind (Objektart, Liste KINDS unten),
--    name, water (Name des nächsten Gewässers, setzt update.sh), tags (jsonb, nur die Merkmale aus KEEP),
--    geom (Punkt, Linie, Fläche oder Multifläche, SRID 4326).
--    Brücken nimmt osm2pgsql zuerst alle auf; update.sh behält nur die, die ein Gewässer aus sw_waterways kreuzen.
--
-- osm2pgsql legt je Tabelle einen GiST-Index auf geom an; den Index auf kind setzt update.sh.

local ways = osm2pgsql.define_table({
    name = 'sw_waterways',
    ids = { type = 'way', id_column = 'id' },
    columns = {
        { column = 'waterway', type = 'text', not_null = true },
        { column = 'name', type = 'text' },
        { column = 'geom', type = 'linestring', projection = 4326, not_null = true },
    },
})

local features = osm2pgsql.define_table({
    name = 'sw_features',
    ids = { type = 'any', type_column = 'osm_type', id_column = 'osm_id' },
    columns = {
        { column = 'kind', type = 'text', not_null = true },
        { column = 'name', type = 'text' },
        { column = 'water', type = 'text' },
        { column = 'tags', type = 'jsonb' },
        { column = 'geom', type = 'geometry', projection = 4326, not_null = true },
    },
})

local keep = { river = true, stream = true, canal = true }

-- Objektarten (Schlüssel wie GEO_KINDS in der App):
--   dam     Staumauer / Damm               waterway=dam
--   weir    Wehr / Schwelle                waterway=weir|drop
--   plant   Wasserkraftwerk                power=plant + plant:source=hydro, power=generator + generator:source=hydro
--   lock    Schleuse                       waterway=lock_gate, lock=yes
--   bridge  Brücke über ein Gewässer       bridge=* auf highway/railway (nur Wege)
--   slip    Slipanlage / Einwasserung      leisure=slipway, canoe=put_in, whitewater=put_in
--   egress  Ausstieg                       canoe=egress, whitewater=egress
--   fall    Wasserfall / Stromschnelle     waterway=waterfall|rapids, whitewater=rapid
--   rack    Rechen / Treibgutfang          waterway=debris_screen, man_made=debris_screen
--   rescue  Rettungspunkt                  highway=emergency_access_point, emergency=access_point
--   ring    Rettungsring                   emergency=life_ring
--   pier    Steg / Anlegestelle            man_made=pier, leisure=marina
local function has(v, ...)
    if not v then return false end
    for _, w in ipairs({ ... }) do
        for part in string.gmatch(v, '[^;]+') do
            if part == w then return true end
        end
    end
    return false
end

local function classify(t, is_way)
    local ww = t.waterway
    if ww == 'dam' then return 'dam' end
    if ww == 'weir' or ww == 'drop' then return 'weir' end
    if ww == 'lock_gate' or t.lock == 'yes' then return 'lock' end
    if ww == 'waterfall' or ww == 'rapids' or has(t.whitewater, 'rapid') then return 'fall' end
    if ww == 'debris_screen' or t.man_made == 'debris_screen' then return 'rack' end
    if (t.power == 'plant' and t['plant:source'] == 'hydro')
        or (t.power == 'generator' and t['generator:source'] == 'hydro') then return 'plant' end
    if t.leisure == 'slipway' or has(t.canoe, 'put_in') or has(t.whitewater, 'put_in') then return 'slip' end
    if has(t.canoe, 'egress') or has(t.whitewater, 'egress') then return 'egress' end
    if t.highway == 'emergency_access_point' or t.emergency == 'access_point' then return 'rescue' end
    if t.emergency == 'life_ring' then return 'ring' end
    if t.man_made == 'pier' or t.leisure == 'marina' then return 'pier' end
    if is_way and t.bridge and t.bridge ~= 'no' and (t.highway or t.railway) then return 'bridge' end
    return nil
end

-- Arten, die als geschlossener Weg eine Fläche sind (sonst Linie, z. B. Wehr quer zum Fluss)
local area_kinds = { plant = true, dam = true, pier = true, slip = true }

-- Merkmale, die in tags übernommen werden (Anzeige, Vorausfüllen der Beschreibung, Abgleich mit der Quelle)
local KEEP = {
    'name', 'bridge:name', 'ref', 'operator', 'description', 'note', 'access', 'website', 'wikidata', 'start_date',
    'waterway', 'power', 'plant:source', 'generator:source', 'plant:output:electricity', 'generator:output:electricity',
    'leisure', 'man_made', 'emergency', 'highway', 'railway', 'bridge', 'bridge:structure', 'layer',
    'canoe', 'whitewater', 'lock', 'height', 'width', 'length', 'material', 'maxweight', 'maxheight',
    'motorboat', 'boat', 'surface', 'fee', 'opening_hours', 'phone',
}

local function pick(t)
    local r, n = {}, 0
    for _, k in ipairs(KEEP) do
        if t[k] then r[k] = t[k]; n = n + 1 end
    end
    if n == 0 then return nil end
    return r
end

local function name_of(t)
    return t.name or t['bridge:name']
end

function osm2pgsql.process_node(object)
    local kind = classify(object.tags, false)
    if not kind then return end
    features:insert({
        kind = kind,
        name = name_of(object.tags),
        tags = pick(object.tags),
        geom = object:as_point(),
    })
end

function osm2pgsql.process_way(object)
    local t = object.tags
    local w = t.waterway
    if keep[w] then
        ways:insert({
            waterway = w,
            name = t.name,
            geom = object:as_linestring(),
        })
    end

    local kind = classify(t, true)
    if not kind then return end
    local geom
    if object.is_closed and area_kinds[kind] and t.area ~= 'no' then
        geom = object:as_polygon()
    else
        geom = object:as_linestring()
    end
    features:insert({
        kind = kind,
        name = name_of(t),
        tags = pick(t),
        geom = geom,
    })
end

-- Multiflächen (z. B. Kraftwerksgelände als Relation)
function osm2pgsql.process_relation(object)
    if object.tags.type ~= 'multipolygon' then return end
    local kind = classify(object.tags, false)
    if not kind or not area_kinds[kind] then return end
    features:insert({
        kind = kind,
        name = name_of(object.tags),
        tags = pick(object.tags),
        geom = object:as_multipolygon(),
    })
end
