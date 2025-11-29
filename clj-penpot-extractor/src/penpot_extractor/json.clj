;; JSON conversion utilities for Penpot data

(ns penpot-extractor.json
  (:require
   [clojure.data.json :as json]
   [linked.map :as lkm]
   [linked.set :as lks])
  (:import
   java.time.Instant
   java.util.UUID
   linked.map.LinkedMap
   linked.set.LinkedSet))

(set! *warn-on-reflection* true)

(defn- convert-value
  "Convert special Clojure/Penpot types to JSON-serializable values."
  [v]
  (cond
    (uuid? v) (str v)
    (inst? v) (str v)
    (keyword? v) (if-let [ns (namespace v)]
                   (str ns "/" (name v))
                   (name v))
    (symbol? v) (str v)
    (ratio? v) (double v)
    (instance? LinkedMap v) v
    (instance? LinkedSet v) (vec v)
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

(defn encode
  "Encode data to JSON string with proper type conversion."
  [data & {:keys [pretty?] :or {pretty? true}}]
  (json/write-str (deep-convert data)
                  :indent pretty?))

(defn encode-file-data
  "Encode file data with metadata to JSON.
   
   Options:
   - :include-file-info? - Include file metadata (default: true)
   - :pretty?            - Pretty print JSON (default: true)"
  [file-record decoded-data & {:keys [include-file-info? pretty?]
                                :or {include-file-info? true pretty? true}}]
  (let [result (if include-file-info?
                 {:id (str (:id file-record))
                  :name (:name file-record)
                  :project-id (str (:project_id file-record))
                  :created-at (some-> (:created_at file-record) str)
                  :modified-at (some-> (:modified_at file-record) str)
                  :revn (:revn file-record)
                  :features (vec (:features file-record))
                  :data (deep-convert decoded-data)}
                 (deep-convert decoded-data))]
    (json/write-str result :indent pretty?)))
