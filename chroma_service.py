import json
import os
import sys
from http.server import BaseHTTPRequestHandler, HTTPServer
import chromadb

# Initialize ChromaDB client persistent storage
CHROMA_DIR = os.path.join(os.path.dirname(os.path.abspath(__file__)), "chroma_db")
client = chromadb.PersistentClient(path=CHROMA_DIR)

# Get or create collection
collection = client.get_or_create_collection("notenest_collection")

class ChromaHTTPHandler(BaseHTTPRequestHandler):
    def log_message(self, format, *args):
        # Silence default terminal logs
        pass

    def do_POST(self):
        content_length = int(self.headers['Content-Length'])
        post_data = self.rfile.read(content_length)
        
        try:
            data = json.loads(post_data.decode('utf-8'))
        except Exception as e:
            self.send_response(400)
            self.send_header('Content-Type', 'application/json')
            self.end_headers()
            self.wfile.write(json.dumps({"success": False, "error": "Invalid JSON"}).encode('utf-8'))
            return
            
        action = self.path
        
        if action == "/add":
            # Extract parameters
            file_id = int(data.get("file_id", 0))
            user_id = int(data.get("user_id", 0))
            
            course_id = data.get("course_id")
            if course_id is not None:
                course_id = int(course_id)
                
            folder_id = data.get("folder_id")
            if folder_id is not None:
                folder_id = int(folder_id)
                
            topic_id = data.get("topic_id")
            if topic_id is not None:
                topic_id = int(topic_id)
                
            chunks = data.get("chunks", [])
            
            # First, delete existing chunks for this file
            try:
                collection.delete(where={"file_id": file_id})
            except Exception:
                pass
                
            if not chunks:
                self.send_json({"success": True, "message": "No chunks to add"})
                return
                
            ids = []
            documents = []
            metadatas = []
            
            for idx, c in enumerate(chunks):
                chunk_id = f"chunk_{file_id}_{idx}"
                page = int(c.get("page", 1))
                content = c.get("content", "").strip()
                
                if not content:
                    continue
                    
                ids.append(chunk_id)
                documents.append(content)
                
                meta = {
                    "user_id": user_id,
                    "file_id": file_id,
                    "page_number": page
                }
                if course_id is not None:
                    meta["course_id"] = course_id
                if folder_id is not None:
                    meta["folder_id"] = folder_id
                if topic_id is not None:
                    meta["topic_id"] = topic_id
                    
                metadatas.append(meta)
                
            if ids:
                collection.add(
                    ids=ids,
                    documents=documents,
                    metadatas=metadatas
                )
                
            self.send_json({"success": True, "added": len(ids)})
            
        elif action == "/query":
            user_id = int(data.get("user_id", 0))
            query_text = data.get("query", "").strip()
            course_id = data.get("course_id")
            folder_id = data.get("folder_id")
            file_ids = data.get("file_ids", [])
            limit = int(data.get("limit", 5))
            
            if not query_text:
                self.send_json({"success": True, "results": []})
                return
                
            # Build filters
            conditions = [{"user_id": user_id}]
            
            if course_id is not None and int(course_id) > 0:
                conditions.append({"course_id": int(course_id)})
            if folder_id is not None and int(folder_id) > 0:
                conditions.append({"folder_id": int(folder_id)})
                
            if file_ids:
                file_ids_ints = [int(f) for f in file_ids if f]
                if len(file_ids_ints) == 1:
                    conditions.append({"file_id": file_ids_ints[0]})
                elif len(file_ids_ints) > 1:
                    conditions.append({"file_id": {"$in": file_ids_ints}})
                    
            if len(conditions) == 1:
                where_filter = conditions[0]
            else:
                where_filter = {"$and": conditions}
                
            results = collection.query(
                query_texts=[query_text],
                n_results=limit,
                where=where_filter
            )
            
            formatted = []
            if results and results.get("documents") and len(results["documents"]) > 0:
                docs = results["documents"][0]
                metas = results["metadatas"][0]
                ids = results["ids"][0]
                distances = results["distances"][0] if "distances" in results else [0.0] * len(docs)
                
                for i in range(len(docs)):
                    dist = distances[i]
                    # Cosine similarity representation
                    confidence = max(0, min(100, int((1.0 - max(0, dist)) * 100)))
                    
                    formatted.append({
                        "id": ids[i],
                        "content": docs[i],
                        "page_number": metas[i].get("page_number", 1),
                        "file_id": metas[i].get("file_id"),
                        "course_id": metas[i].get("course_id"),
                        "folder_id": metas[i].get("folder_id"),
                        "confidence_score": confidence
                    })
                    
            self.send_json({"success": True, "results": formatted})
            
        elif action == "/delete_file":
            file_id = int(data.get("file_id", 0))
            collection.delete(where={"file_id": file_id})
            self.send_json({"success": True, "message": "File chunks deleted"})
            
        else:
            self.send_response(404)
            self.end_headers()

    def send_json(self, response_data):
        self.send_response(200)
        self.send_header('Content-Type', 'application/json')
        self.end_headers()
        self.wfile.write(json.dumps(response_data).encode('utf-8'))

def run(port=8000):
    server_address = ('127.0.0.1', port)
    httpd = HTTPServer(server_address, ChromaHTTPHandler)
    print(f"Chroma local service running on port {port}...")
    try:
        httpd.serve_forever()
    except KeyboardInterrupt:
        pass
    httpd.server_close()

if __name__ == "__main__":
    port = 8000
    if len(sys.argv) > 1:
        port = int(sys.argv[1])
    run(port)
