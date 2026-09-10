import http from 'http'
import fs from 'fs'
import path from 'path'

const DIST = path.join(__dirname, 'dist')
const API_TARGET = 'http://127.0.0.1:8000'

const MIME = {
  '.html': 'text/html',
  '.js': 'application/javascript',
  '.css': 'text/css',
  '.json': 'application/json',
  '.png': 'image/png',
  '.svg': 'image/svg+xml',
}

const server = http.createServer((req, res) => {
  if (req.url?.startsWith('/api/')) {
    const url = new URL(req.url, API_TARGET)
    const proxyReq = http.request(url, { method: req.method, headers: req.headers }, (proxyRes) => {
      res.writeHead(proxyRes.statusCode || 500, proxyRes.headers)
      proxyRes.pipe(res)
    })
    proxyReq.on('error', () => {
      res.writeHead(502)
      res.end('Bad Gateway')
    })
    req.pipe(proxyReq)
    return
  }

  let filePath = path.join(DIST, req.url === '/' ? 'index.html' : req.url || '')
  if (!fs.existsSync(filePath)) {
    filePath = path.join(DIST, 'index.html')
  }
  const ext = path.extname(filePath)
  const mime = MIME[ext] || 'application/octet-stream'
  const content = fs.readFileSync(filePath)
  res.writeHead(200, { 'Content-Type': mime })
  res.end(content)
})

server.listen(5173, () => {
  console.log('Test server running on http://localhost:5173')
})
