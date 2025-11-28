;; Standalone Transit implementation for Penpot
;; Based on Penpot's app.common.transit but without external app dependencies

(ns penpot-extractor.transit
  (:require
   [cognitect.transit :as t]
   [linked.map :as lkm]
   [linked.set :as lks]
   [lambdaisland.uri :as luri])
  (:import
   java.io.ByteArrayInputStream
   java.io.ByteArrayOutputStream
   java.time.Duration
   java.time.Instant
   java.time.OffsetDateTime
   lambdaisland.uri.URI
   linked.map.LinkedMap
   linked.set.LinkedSet))

(set! *warn-on-reflection* true)

(def write-handlers (atom nil))
(def read-handlers (atom nil))
(def write-handler-map (atom nil))
(def read-handler-map (atom nil))

;; A generic pointer type for deserialization
(deftype Pointer [id metadata]
  clojure.lang.IObj
  (meta [_] metadata)
  (withMeta [_ meta] (Pointer. id meta))
  clojure.lang.IDeref
  (deref [_] id))

(defn pointer?
  [o]
  (instance? Pointer o))

;; --- HELPERS

(defn- str->bytes
  ([^String s]
   (str->bytes s "UTF-8"))
  ([^String s, ^String encoding]
   (.getBytes s encoding)))

(defn- bytes->str
  ([^bytes data]
   (bytes->str data "UTF-8"))
  ([^bytes data, ^String encoding]
   (String. data encoding)))

(defn- without-nils
  [m]
  (into {} (remove (comp nil? val)) m))

(defn add-handlers!
  [& handlers]
  (letfn [(adapt-write-handler [{:keys [id class wfn]}]
            [class (t/write-handler (constantly id) wfn)])

          (adapt-read-handler [{:keys [id rfn]}]
            [id (t/read-handler rfn)])

          (merge-and-clean [m1 m2]
            (-> (merge m1 m2)
                (without-nils)))]

    (let [rhs (into {}
                    (comp
                     (filter :rfn)
                     (map adapt-read-handler))
                    handlers)
          whs (into {}
                    (comp
                     (filter :wfn)
                     (map adapt-write-handler))
                    handlers)
          cwh (swap! write-handlers merge-and-clean whs)
          crh (swap! read-handlers merge-and-clean rhs)]

      (reset! write-handler-map (t/write-handler-map cwh))
      (reset! read-handler-map (t/read-handler-map crh))
      nil)))

;; --- HANDLERS

(add-handlers!
 {:id "ordered-map"
  :class LinkedMap
  :wfn vec
  :rfn #(into lkm/empty-linked-map %)}

 {:id "ordered-set"
  :class LinkedSet
  :wfn vec
  :rfn #(into lks/empty-linked-set %)}

 {:id "duration"
  :class Duration
  :rfn (fn [v] (Duration/ofMillis v))
  :wfn inst-ms}

 {:id "m"
  :class Instant
  :rfn (fn [v]
         (-> (Long/parseLong v)
             (Instant/ofEpochMilli)))
  :wfn (comp str inst-ms)}

 {:id "penpot/pointer"
  :class Pointer
  :rfn (fn [[id meta]]
         (Pointer. id meta))}

 {:id "m"
  :class OffsetDateTime
  :wfn (comp str inst-ms)}

 {:id "uri"
  :class URI
  :rfn luri/uri
  :wfn str})

;; --- Low-Level Api

(defn reader
  ([istream]
   (reader istream nil))
  ([istream {:keys [type] :or {type :json}}]
   (t/reader istream type {:handlers @read-handler-map})))

(defn writer
  ([ostream]
   (writer ostream nil))
  ([ostream {:keys [type] :or {type :json}}]
   (t/writer ostream type {:handlers @write-handler-map})))

(defn read!
  [reader]
  (t/read reader))

(defn write!
  [writer data]
  (t/write writer data))

;; --- High-Level Api

(defn encode
  ([data] (encode data nil))
  ([data opts]
   (with-open [out (ByteArrayOutputStream.)]
     (t/write (writer out opts) data)
     (.toByteArray out))))

(defn decode
  ([data] (decode data nil))
  ([data opts]
   (with-open [input (ByteArrayInputStream. ^bytes data)]
     (t/read (reader input opts)))))

(defn encode-str
  ([data] (encode-str data nil))
  ([data opts]
   (->> (encode data opts)
        (bytes->str))))

(defn decode-str
  ([data] (decode-str data nil))
  ([data opts]
   (-> (str->bytes data)
       (decode opts))))
