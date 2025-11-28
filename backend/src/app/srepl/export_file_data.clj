;; This Source Code Form is subject to the terms of the Mozilla Public
;; License, v. 2.0. If a copy of the MPL was not distributed with this
;; file, You can obtain one at http://mozilla.org/MPL/2.0/.
;;
;; Copyright (c) KALEIDOS INC

(ns app.srepl.export-file-data
  "Utilities for exporting file data from the database to readable JSON format.

  The file data in Penpot is stored in PostgreSQL as ByteA column using:
  - Serialization: Either Transit JSON or Fressian
  - Compression: Either LZ4 or Zstd
  - Header: 2-byte version number + 4-byte uncompressed length

  Blob versions:
  - v1: Transit JSON + LZ4
  - v3: Transit JSON + Zstd
  - v4: Fressian + Zstd
  - v5: Fressian + LZ4 Frame (current default)

  This namespace provides utilities to extract and decode file data,
  and convert it to standard JSON format for external API consumption."
  (:require
   [app.binfile.common :as bfc]
   [app.common.data :as d]
   [app.common.json :as json]
   [app.common.transit :as transit]
   [app.db :as db]
   [app.features.fdata :as fdata]
   [app.main :as main]
   [app.util.blob :as blob]
   [app.util.pointer-map :as pmap]
   [clojure.java.io :as io])
  (:import
   java.io.Writer))

(set! *warn-on-reflection* true)

;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;
;; CORE EXTRACTION FUNCTIONS
;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;

(defn decode-raw-blob
  "Decode a raw blob (ByteA) to Clojure data structure.

  The blob is expected to have the penpot encoding format:
  - 2-byte version header
  - 4-byte uncompressed length
  - Compressed data

  Returns the decoded Clojure data structure (map, vector, etc.)"
  [^bytes data]
  (when data
    (blob/decode data)))

(defn get-file-data
  "Retrieve the complete file data for a given file-id.

  Options:
  - :realize? - If true, resolves all pointer-maps to plain data (default: true)
  - :migrate? - If true, applies any pending migrations (default: true)

  Returns a map with the file data including:
  - :id - file uuid
  - :name - file name
  - :project-id - project uuid
  - :data - the complete file data structure"
  ([file-id]
   (get-file-data main/system file-id))
  ([system file-id]
   (get-file-data system file-id {}))
  ([system file-id {:keys [realize? migrate?]
                    :or {realize? true migrate? true}}]
   (db/run! system
            (fn [cfg]
              (binding [pmap/*load-fn* (partial fdata/load-pointer cfg file-id)]
                (let [file (bfc/get-file cfg file-id
                                         :migrate? migrate?
                                         :realize? realize?)]
                  (cond-> file
                    realize?
                    (update :data fdata/process-pointers deref))))))))

(defn get-file-data-raw
  "Retrieve the raw (not decoded) file data for a given file-id.

  Returns a map with :data as the raw encoded bytes if stored in the
  legacy format, or resolved from the file_data table."
  ([file-id]
   (get-file-data-raw main/system file-id))
  ([system file-id]
   (db/run! system
            (fn [cfg]
              (bfc/get-file cfg file-id :decode? false)))))

;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;
;; JSON CONVERSION FUNCTIONS
;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;

(defn- convert-value
  "Convert special Clojure/penpot types to JSON-serializable values."
  [v]
  (cond
    (uuid? v) (str v)
    (inst? v) (str v)
    (keyword? v) (if-let [ns (namespace v)]
                   (str ns "/" (name v))
                   (name v))
    (symbol? v) (str v)
    (ratio? v) (double v)
    :else v))

(defn- deep-convert
  "Recursively convert all values in a data structure to JSON-compatible types."
  [data]
  (cond
    (map? data)
    (into {}
          (map (fn [[k v]]
                 [(convert-value k) (deep-convert v)]))
          data)

    (sequential? data)
    (mapv deep-convert data)

    (set? data)
    (mapv deep-convert data)

    :else
    (convert-value data)))

(defn file-data->json
  "Convert file data to a JSON string.

  Options:
  - :pretty? - If true, format the JSON with indentation (default: true)
  - :include-file-info? - If true, include file metadata (default: true)

  Returns a JSON string."
  ([file-data]
   (file-data->json file-data {}))
  ([file-data {:keys [pretty? include-file-info?]
               :or {pretty? true include-file-info? true}}]
   (let [data (if include-file-info?
                {:id (str (:id file-data))
                 :name (:name file-data)
                 :project-id (str (:project-id file-data))
                 :created-at (some-> (:created-at file-data) str)
                 :modified-at (some-> (:modified-at file-data) str)
                 :revn (:revn file-data)
                 :version (:version file-data)
                 :features (mapv str (:features file-data))
                 :data (deep-convert (:data file-data))}
                (deep-convert (:data file-data)))]
     (json/encode data :indent (when pretty? 2)))))

(defn file-data->transit-json
  "Convert file data to Transit JSON string.

  Transit JSON preserves more type information than plain JSON,
  which may be useful for round-tripping data.

  Options:
  - :verbose? - If true, use verbose transit format (default: false)"
  ([file-data]
   (file-data->transit-json file-data {}))
  ([file-data {:keys [verbose?]
               :or {verbose? false}}]
   (transit/encode-str (:data file-data)
                       {:type (if verbose? :json-verbose :json)})))

;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;
;; FILE EXPORT FUNCTIONS
;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;

(defn export-file-to-json!
  "Export a file's data to a JSON file.

  Arguments:
  - system - The system configuration (optional, uses main/system if not provided)
  - file-id - UUID of the file to export
  - output-path - Path to the output JSON file

  Options:
  - :pretty? - If true, format JSON with indentation (default: true)
  - :include-file-info? - Include file metadata (default: true)"
  ([file-id output-path]
   (export-file-to-json! main/system file-id output-path {}))
  ([system file-id output-path]
   (export-file-to-json! system file-id output-path {}))
  ([system file-id output-path opts]
   (let [file-data (get-file-data system file-id)
         json-str  (file-data->json file-data opts)]
     (with-open [^Writer writer (io/writer output-path)]
       (.write writer ^String json-str))
     {:status :success
      :file-id (str file-id)
      :output-path output-path
      :bytes-written (count json-str)})))

(defn export-raw-blob-to-json
  "Given raw blob bytes (from direct database query), decode and convert to JSON.

  This is useful when you have directly queried the data column from
  the file table using raw SQL."
  [^bytes blob-data & {:as opts}]
  (let [decoded-data (decode-raw-blob blob-data)]
    (json/encode (deep-convert decoded-data)
                 :indent (when (:pretty? opts true) 2))))

;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;
;; DIRECT DATABASE ACCESS (for advanced use cases)
;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;

(def ^:private sql:get-file-blob
  "SELECT f.id, f.name, f.data, f.features, f.project_id
     FROM file AS f
    WHERE f.id = ?
      AND f.deleted_at IS NULL")

(defn get-file-blob-from-db
  "Directly query the file table and return the raw blob data.

  This bypasses the normal file loading mechanism and returns raw data
  for cases where you need low-level access.

  Returns a map with:
  - :id - file uuid
  - :name - file name
  - :data - raw ByteA data (or nil if stored in file_data table)
  - :features - array of feature flags"
  ([file-id]
   (get-file-blob-from-db main/system file-id))
  ([system file-id]
   (db/run! system
            (fn [{:keys [::db/conn]}]
              (when-let [row (db/get-with-sql conn [sql:get-file-blob file-id]
                                              {::db/throw-if-not-exists false})]
                (-> row
                    (d/update-when :features db/decode-pgarray #{})))))))

(defn extract-and-decode-file-blob
  "Extract file data from database and decode to Clojure data.

  This combines get-file-blob-from-db with blob decoding.
  If the file data is in the new file_data table, it will also
  fetch from there.

  Returns a map with:
  - :id - file uuid
  - :name - file name
  - :data - decoded Clojure data structure
  - :features - set of feature flags"
  ([file-id]
   (extract-and-decode-file-blob main/system file-id))
  ([system file-id]
   (db/run! system
            (fn [cfg]
              (let [file (bfc/get-file cfg file-id
                                       :migrate? true
                                       :realize? true)]
                {:id (:id file)
                 :name (:name file)
                 :project-id (:project-id file)
                 :features (:features file)
                 :data (:data file)})))))
