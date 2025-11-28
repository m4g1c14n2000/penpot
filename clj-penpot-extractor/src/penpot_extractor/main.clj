;; Main entry point for Penpot Extractor

(ns penpot-extractor.main
  (:require
   [clojure.string :as str]
   [penpot-extractor.blob :as blob]
   [penpot-extractor.db :as db]
   [penpot-extractor.json :as json])
  (:gen-class))

(set! *warn-on-reflection* true)

(defn- print-help
  []
  (println "
Penpot File Extractor - Standalone tool to extract Penpot file data as JSON

USAGE:
  clj -M:run <command> [options]

COMMANDS:
  extract <file-id>    Extract file data as JSON
  info <file-id>       Get file info without data
  media <file-id>      List media objects for a file
  list-files <project-id>  List files in a project
  list-projects <team-id>  List projects in a team
  list-teams           List all teams
  version <file-id>    Check blob version of a file

REQUIRED ENVIRONMENT VARIABLES:
  PENPOT_DB_HOST       PostgreSQL host (default: localhost)
  PENPOT_DB_PORT       PostgreSQL port (default: 5432)
  PENPOT_DB_NAME       Database name (default: penpot)
  PENPOT_DB_USER       Database user (default: penpot)
  PENPOT_DB_PASSWORD   Database password (required)
  PENPOT_PUBLIC_URI    Public URI for asset URLs (optional)

EXAMPLES:
  # Set environment variables
  export PENPOT_DB_HOST=localhost
  export PENPOT_DB_PORT=5432
  export PENPOT_DB_NAME=penpot
  export PENPOT_DB_USER=penpot
  export PENPOT_DB_PASSWORD=your-password
  export PENPOT_PUBLIC_URI=https://your-penpot.com

  # Extract file data
  clj -M:run extract 550e8400-e29b-41d4-a716-446655440000

  # Check blob version
  clj -M:run version 550e8400-e29b-41d4-a716-446655440000
"))

(defn- get-db-config
  []
  {:host (System/getenv "PENPOT_DB_HOST")
   :port (some-> (System/getenv "PENPOT_DB_PORT") Integer/parseInt)
   :dbname (System/getenv "PENPOT_DB_NAME")
   :user (System/getenv "PENPOT_DB_USER")
   :password (System/getenv "PENPOT_DB_PASSWORD")})

(defn- get-public-uri
  []
  (System/getenv "PENPOT_PUBLIC_URI"))

(defn- build-media-url
  [public-uri media-id]
  (when (and public-uri media-id)
    (str (str/trimr public-uri "/") "/assets/by-id/" media-id)))

(defn- cmd-extract
  [file-id]
  (let [config (get-db-config)
        _ (when-not (:password config)
            (println "ERROR: PENPOT_DB_PASSWORD environment variable is required")
            (System/exit 1))
        ds (db/create-datasource config)
        file-record (db/get-file ds file-id)]
    (if file-record
      (let [data-bytes (:data file-record)
            decoded-data (blob/decode data-bytes)
            json-str (json/encode-file-data file-record decoded-data)]
        (println json-str))
      (do
        (println (str "ERROR: File not found: " file-id))
        (System/exit 1)))))

(defn- cmd-info
  [file-id]
  (let [config (get-db-config)
        _ (when-not (:password config)
            (println "ERROR: PENPOT_DB_PASSWORD environment variable is required")
            (System/exit 1))
        ds (db/create-datasource config)
        file-record (db/get-file-info ds file-id)]
    (if file-record
      (println (json/encode file-record))
      (do
        (println (str "ERROR: File not found: " file-id))
        (System/exit 1)))))

(defn- cmd-media
  [file-id]
  (let [config (get-db-config)
        _ (when-not (:password config)
            (println "ERROR: PENPOT_DB_PASSWORD environment variable is required")
            (System/exit 1))
        ds (db/create-datasource config)
        public-uri (get-public-uri)
        media-objects (db/get-file-media-objects ds file-id)
        media-with-urls (map (fn [m]
                               (cond-> m
                                 (:media_id m)
                                 (assoc :media_url (build-media-url public-uri (:media_id m)))
                                 (:thumbnail_id m)
                                 (assoc :thumbnail_url (build-media-url public-uri (:thumbnail_id m)))))
                             media-objects)]
    (println (json/encode media-with-urls))))

(defn- cmd-list-files
  [project-id]
  (let [config (get-db-config)
        _ (when-not (:password config)
            (println "ERROR: PENPOT_DB_PASSWORD environment variable is required")
            (System/exit 1))
        ds (db/create-datasource config)
        files (db/list-files ds project-id)]
    (println (json/encode files))))

(defn- cmd-list-projects
  [team-id]
  (let [config (get-db-config)
        _ (when-not (:password config)
            (println "ERROR: PENPOT_DB_PASSWORD environment variable is required")
            (System/exit 1))
        ds (db/create-datasource config)
        projects (db/list-projects ds team-id)]
    (println (json/encode projects))))

(defn- cmd-list-teams
  []
  (let [config (get-db-config)
        _ (when-not (:password config)
            (println "ERROR: PENPOT_DB_PASSWORD environment variable is required")
            (System/exit 1))
        ds (db/create-datasource config)
        teams (db/list-teams ds)]
    (println (json/encode teams))))

(defn- cmd-version
  [file-id]
  (let [config (get-db-config)
        _ (when-not (:password config)
            (println "ERROR: PENPOT_DB_PASSWORD environment variable is required")
            (System/exit 1))
        ds (db/create-datasource config)
        file-record (db/get-file ds file-id)]
    (if file-record
      (let [data-bytes (:data file-record)
            version (blob/get-version data-bytes)]
        (println (json/encode {:file-id file-id
                               :blob-version version
                               :format (case version
                                         1 "Transit JSON + LZ4"
                                         3 "Transit JSON + Zstd"
                                         4 "Fressian + Zstd"
                                         5 "Fressian + LZ4 Frame"
                                         "Unknown")})))
      (do
        (println (str "ERROR: File not found: " file-id))
        (System/exit 1)))))

(defn -main
  [& args]
  (if (empty? args)
    (print-help)
    (let [[cmd & cmd-args] args]
      (case cmd
        "extract" (if (first cmd-args)
                    (cmd-extract (first cmd-args))
                    (println "ERROR: file-id required"))
        "info" (if (first cmd-args)
                 (cmd-info (first cmd-args))
                 (println "ERROR: file-id required"))
        "media" (if (first cmd-args)
                  (cmd-media (first cmd-args))
                  (println "ERROR: file-id required"))
        "list-files" (if (first cmd-args)
                       (cmd-list-files (first cmd-args))
                       (println "ERROR: project-id required"))
        "list-projects" (if (first cmd-args)
                          (cmd-list-projects (first cmd-args))
                          (println "ERROR: team-id required"))
        "list-teams" (cmd-list-teams)
        "version" (if (first cmd-args)
                    (cmd-version (first cmd-args))
                    (println "ERROR: file-id required"))
        "help" (print-help)
        "--help" (print-help)
        "-h" (print-help)
        (do
          (println (str "Unknown command: " cmd))
          (print-help))))))
