-- osm2pgsql (Flex-Ausgabe): Gewässernetz für Swiftwater Rescue (Issue #131)
-- Nur Wege mit waterway=river|stream|canal, wie die Overpass-Abfrage der App (overpassQuery in web/api/index.php).
-- Tabelle sw_waterways: id (OSM-Weg), waterway, name, geom (LineString, SRID 4326). osm2pgsql legt dazu einen GiST-Index auf geom an.
-- Knoten braucht die App nicht: Sie verbindet Wege über gleiche Koordinaten (pgWays), die an Zusammenflüssen vom selben OSM-Knoten stammen.

local ways = osm2pgsql.define_table({
    name = 'sw_waterways',
    ids = { type = 'way', id_column = 'id' },
    columns = {
        { column = 'waterway', type = 'text', not_null = true },
        { column = 'name', type = 'text' },
        { column = 'geom', type = 'linestring', projection = 4326, not_null = true },
    },
})

local keep = { river = true, stream = true, canal = true }

function osm2pgsql.process_way(object)
    local w = object.tags.waterway
    if not keep[w] then
        return
    end
    ways:insert({
        waterway = w,
        name = object.tags.name,
        geom = object:as_linestring(),
    })
end
