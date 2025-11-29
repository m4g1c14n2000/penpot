;; PostgreSQL Database access for Penpot file extraction

(ns penpot-extractor.db
  (:require
   [next.jdbc :as jdbc]
   [next.jdbc.result-set :as rs]))

(set! *warn-on-reflection* true)

(defn create-datasource
  "Create a database connection datasource.
   
   Options:
   - :host     - PostgreSQL host (default: localhost)
   - :port     - PostgreSQL port (default: 5432)
   - :dbname   - Database name (default: penpot)
   - :user     - Username (default: penpot)
   - :password - Password (required)"
  [{:keys [host port dbname user password]}]
  (jdbc/get-datasource
   {:dbtype "postgresql"
    :dbname (or dbname "penpot")
    :host (or host "localhost")
    :port (or port 5432)
    :user (or user "penpot")
    :password password}))

(defn get-file
  "Get file record by ID."
  [ds file-id]
  (jdbc/execute-one! ds
    ["SELECT id, name, project_id, created_at, modified_at, revn, data, features 
      FROM file 
      WHERE id = ?::uuid AND deleted_at IS NULL"
     (str file-id)]
    {:builder-fn rs/as-unqualified-lower-maps}))

(defn get-file-info
  "Get file info without the data blob."
  [ds file-id]
  (jdbc/execute-one! ds
    ["SELECT id, name, project_id, created_at, modified_at, revn, features 
      FROM file 
      WHERE id = ?::uuid AND deleted_at IS NULL"
     (str file-id)]
    {:builder-fn rs/as-unqualified-lower-maps}))

(defn get-file-media-objects
  "Get media objects for a file."
  [ds file-id]
  (jdbc/execute! ds
    ["SELECT id, name, width, height, mtype, media_id, thumbnail_id, is_local
      FROM file_media_object
      WHERE file_id = ?::uuid AND deleted_at IS NULL"
     (str file-id)]
    {:builder-fn rs/as-unqualified-lower-maps}))

(defn list-files
  "List files in a project."
  [ds project-id]
  (jdbc/execute! ds
    ["SELECT id, name, created_at, modified_at, revn, is_shared
      FROM file
      WHERE project_id = ?::uuid AND deleted_at IS NULL
      ORDER BY modified_at DESC"
     (str project-id)]
    {:builder-fn rs/as-unqualified-lower-maps}))

(defn list-projects
  "List projects in a team."
  [ds team-id]
  (jdbc/execute! ds
    ["SELECT id, name, created_at, modified_at
      FROM project
      WHERE team_id = ?::uuid AND deleted_at IS NULL
      ORDER BY modified_at DESC"
     (str team-id)]
    {:builder-fn rs/as-unqualified-lower-maps}))

(defn list-teams
  "List all teams."
  [ds]
  (jdbc/execute! ds
    ["SELECT id, name, created_at, modified_at
      FROM team
      WHERE deleted_at IS NULL
      ORDER BY name"]
    {:builder-fn rs/as-unqualified-lower-maps}))
