/**
 * 本地预览服务器（开发期工具，不进交付包）。
 *
 * 为什么不用 `php -S`（PHP 内置服务器）：
 *   Windows 上 PHP CLI Server 是单线程的（PHP_CLI_SERVER_WORKERS 在 Windows 无效，
 *   启动日志会提示 "forking is not supported on this platform"）。
 *   实测：3 个并发请求只有 1 个成功，另 2 个失败且耗时 30 秒——
 *   而本项目首屏需要「静态资源 + 多个接口」并发，必然会卡住，
 *   表现就是「页面/登录一直转圈」。
 *
 * 本方案：
 *   - 静态文件由 Node 并发处理
 *   - /api.php 与 /uploads/ 交给 php-cgi，**每个请求一个独立进程**（天然并发、互不阻塞）
 *
 * 用法：node tools/devserver.mjs [port]
 */

import http from 'node:http'
import fs from 'node:fs'
import path from 'node:path'
import { spawn } from 'node:child_process'
import { fileURLToPath } from 'node:url'

const __dirname = path.dirname(fileURLToPath(import.meta.url))
const ROOT = path.resolve(__dirname, '..')
const SITE = path.join(ROOT, 'site')
const PORT = Number(process.argv[2] || 8811)

const PHP_CGI = 'C:\\Users\\sl\\AppData\\Local\\Microsoft\\WinGet\\Packages\\PHP.PHP.8.4_Microsoft.Winget.Source_8wekyb3d8bbwe\\php-cgi.exe'
const EXT = 'C:\\Users\\sl\\AppData\\Local\\Microsoft\\WinGet\\Packages\\PHP.PHP.8.4_Microsoft.Winget.Source_8wekyb3d8bbwe\\ext'
const PHP_ARGS = [
  '-d', `extension_dir=${EXT}`,
  '-d', 'extension=pdo_mysql',
  '-d', 'extension=mbstring',
  '-d', 'extension=openssl',
  '-d', 'extension=fileinfo',
  '-d', 'date.timezone=Asia/Shanghai',
  '-d', 'display_errors=0',
]

const MIME = {
  '.html': 'text/html; charset=utf-8',
  '.js': 'application/javascript; charset=utf-8',
  '.css': 'text/css; charset=utf-8',
  '.json': 'application/json; charset=utf-8',
  '.png': 'image/png',
  '.jpg': 'image/jpeg',
  '.jpeg': 'image/jpeg',
  '.gif': 'image/gif',
  '.svg': 'image/svg+xml',
  '.ico': 'image/x-icon',
  '.woff': 'font/woff',
  '.woff2': 'font/woff2',
  '.pdf': 'application/pdf',
  '.txt': 'text/plain; charset=utf-8',
}

/** 用 php-cgi 执行一次 PHP 脚本（每个请求独立进程，天然并发） */
function runPhpCgi(req, body) {
  return new Promise((resolve) => {
    const urlObj = new URL(req.url || '/', `http://127.0.0.1:${PORT}`)
    const scriptName = urlObj.pathname // /api.php
    const scriptFile = path.join(SITE, scriptName)

    const env = {
      ...process.env,
      REDIRECT_STATUS: '1', // php-cgi 要求，防止 "Security Alert"
      SERVER_SOFTWARE: 'devserver',
      SERVER_NAME: '127.0.0.1',
      SERVER_PORT: String(PORT),
      SERVER_PROTOCOL: 'HTTP/1.1',
      GATEWAY_INTERFACE: 'CGI/1.1',
      REQUEST_METHOD: req.method || 'GET',
      REQUEST_URI: req.url || '/',
      SCRIPT_NAME: scriptName,
      SCRIPT_FILENAME: scriptFile,
      PHP_SELF: scriptName,
      DOCUMENT_ROOT: SITE,
      QUERY_STRING: urlObj.search.replace(/^\?/, ''),
      REMOTE_ADDR: '127.0.0.1',
      CONTENT_LENGTH: body && body.length ? String(body.length) : '',
      CONTENT_TYPE: req.headers['content-type'] || '',
      HTTP_HOST: `127.0.0.1:${PORT}`,
      HTTP_AUTHORIZATION: req.headers['authorization'] || '',
      HTTP_ACCEPT: req.headers['accept'] || '*/*',
      HTTP_USER_AGENT: req.headers['user-agent'] || 'devserver',
    }

    const child = spawn(PHP_CGI, PHP_ARGS, { env, cwd: SITE })
    const out = []
    const err = []
    let settled = false

    const timer = setTimeout(() => {
      if (!settled) {
        settled = true
        try { child.kill('SIGKILL') } catch {}
        resolve({ status: 504, headers: { 'Content-Type': 'application/json; charset=utf-8' }, body: Buffer.from(JSON.stringify({ code: 500, data: null, message: '服务超时，请重试' })) })
      }
    }, 20000)

    child.stdout.on('data', (d) => out.push(d))
    child.stderr.on('data', (d) => err.push(d))
    child.on('error', (e) => {
      if (settled) return
      settled = true
      clearTimeout(timer)
      resolve({ status: 502, headers: { 'Content-Type': 'application/json; charset=utf-8' }, body: Buffer.from(JSON.stringify({ code: 500, data: null, message: 'PHP 启动失败：' + e.message })) })
    })
    child.on('close', () => {
      if (settled) return
      settled = true
      clearTimeout(timer)
      if (err.length) {
        const e = Buffer.concat(err).toString('utf8')
        if (!/Deprecated|Warning: Cannot modify header/i.test(e)) {
          process.stderr.write('[php-cgi] ' + e)
        }
      }
      const raw = Buffer.concat(out)
      // 解析 CGI 响应头（以第一个空行分隔）
      const sep = raw.indexOf('\r\n\r\n')
      const sep2 = raw.indexOf('\n\n')
      let headEnd = -1
      let bodyStart = -1
      if (sep >= 0 && (sep2 < 0 || sep < sep2)) {
        headEnd = sep
        bodyStart = sep + 4
      } else if (sep2 >= 0) {
        headEnd = sep2
        bodyStart = sep2 + 2
      }
      if (headEnd < 0) {
        resolve({ status: 200, headers: { 'Content-Type': 'text/html; charset=utf-8' }, body: raw })
        return
      }
      const headText = raw.subarray(0, headEnd).toString('utf8')
      const headers = {}
      let status = 200
      for (const line of headText.split(/\r?\n/)) {
        const idx = line.indexOf(':')
        if (idx <= 0) continue
        const k = line.slice(0, idx).trim()
        const v = line.slice(idx + 1).trim()
        if (k.toLowerCase() === 'status') {
          const m = v.match(/^(\d+)/)
          if (m) status = Number(m[1])
        } else if (k.toLowerCase() !== 'content-length' && k.toLowerCase() !== 'connection') {
          headers[k] = v
        }
      }
      resolve({ status, headers, body: raw.subarray(bodyStart) })
    })

    // php-cgi 默认从 stdin 读 body；无 body 时也要立即 end，否则会一直等待而挂起
    if (body && body.length) {
      child.stdin.write(body)
    }
    child.stdin.end()
  })
}

const server = http.createServer(async (req, res) => {
  const rawPath = (req.url || '/').split('?')[0]
  const url = decodeURIComponent(rawPath)

  // 接口 / 上传目录 / PHP 入口 → php-cgi
  if (url === '/api.php' || url === '/index.php' || url.startsWith('/uploads/')) {
    const chunks = []
    for await (const c of req) chunks.push(c)
    const result = await runPhpCgi(req, Buffer.concat(chunks))
    res.writeHead(result.status, result.headers)
    res.end(result.body)
    return
  }

  // 静态文件由 Node 并发处理
  let filePath = path.join(SITE, url === '/' ? 'index.html' : url)
  if (!filePath.startsWith(SITE)) {
    res.writeHead(403)
    res.end('Forbidden')
    return
  }
  fs.stat(filePath, (err, stat) => {
    if (err || !stat.isFile()) {
      filePath = path.join(SITE, 'index.html')
    }
    const ext = path.extname(filePath).toLowerCase()
    const isHtml = ext === '.html'
    res.writeHead(200, {
      'Content-Type': MIME[ext] || 'application/octet-stream',
      // 入口 HTML 禁缓存；带 hash 的静态资源长缓存
      'Cache-Control': isHtml
        ? 'no-store, no-cache, must-revalidate, max-age=0'
        : 'public, max-age=31536000, immutable',
    })
    fs.createReadStream(filePath).pipe(res)
  })
})

server.listen(PORT, '127.0.0.1', () => {
  console.log(`本地预览已启动：http://127.0.0.1:${PORT}/`)
  console.log('静态资源由 Node 并发处理；接口由 php-cgi 每请求独立进程执行')
})
