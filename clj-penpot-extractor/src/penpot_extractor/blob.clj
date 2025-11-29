;; Penpot Blob Decoder - Standalone Implementation
;; This module decodes Penpot file data blobs without any Penpot dependencies.
;; Supports blob versions 1, 3, 4, and 5.

(ns penpot-extractor.blob
  (:require
   [penpot-extractor.fressian :as fres]
   [penpot-extractor.transit :as transit])
  (:import
   com.github.luben.zstd.Zstd
   java.io.ByteArrayInputStream
   java.io.DataInputStream
   java.io.InputStream
   net.jpountz.lz4.LZ4Factory
   net.jpountz.lz4.LZ4FastDecompressor
   net.jpountz.lz4.LZ4FrameInputStream))

(set! *warn-on-reflection* true)

(def ^:private lz4-factory (LZ4Factory/fastestInstance))

(defn- decode-v1
  "Decode blob version 1: Transit JSON + LZ4"
  [^bytes cdata ^long ulen]
  (let [dcp   (.fastDecompressor ^LZ4Factory lz4-factory)
        udata (byte-array ulen)]
    (.decompress ^LZ4FastDecompressor dcp cdata 6 ^bytes udata 0 ulen)
    (transit/decode udata {:type :json})))

(defn- decode-v3
  "Decode blob version 3: Transit JSON + Zstd"
  [^bytes cdata ^long ulen]
  (let [udata (byte-array ulen)]
    (Zstd/decompressByteArray ^bytes udata 0 ulen
                              ^bytes cdata 6 (- (alength cdata) 6))
    (transit/decode udata {:type :json})))

(defn- decode-v4
  "Decode blob version 4: Fressian + Zstd"
  [^bytes cdata ^long ulen]
  (let [udata (byte-array ulen)]
    (Zstd/decompressByteArray ^bytes udata 0 ulen
                              ^bytes cdata 6 (- (alength cdata) 6))
    (fres/decode udata)))

(defn- decode-v5
  "Decode blob version 5: Fressian + LZ4 Frame"
  [^bytes cdata]
  (with-open [^InputStream input (ByteArrayInputStream. cdata)]
    (.skip input 6)
    (with-open [^InputStream input (LZ4FrameInputStream. input)]
      (-> input fres/reader fres/read!))))

(defn decode
  "Decode a Penpot blob from raw bytes.
   Automatically detects the version from the header."
  [^bytes data]
  (with-open [bais (ByteArrayInputStream. data)
              dis  (DataInputStream. bais)]
    (let [version (.readShort dis)
          ulen    (.readInt dis)]
      (case version
        1 (decode-v1 data ulen)
        3 (decode-v3 data ulen)
        4 (decode-v4 data ulen)
        5 (decode-v5 data)
        (throw (ex-info "Unsupported blob version" {:version version}))))))

(defn get-version
  "Get the blob version without decoding."
  [^bytes data]
  (with-open [bais (ByteArrayInputStream. data)
              dis  (DataInputStream. bais)]
    (.readShort dis)))
