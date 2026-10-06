/**
 * 构建产物组装脚本：把 frontend/dist 的静态产物复制到站点根 site/。
 * 站点根最终包含：index.html + assets/（前端产物）、api.php + inc/ + api_handlers/ + uploads/（后端）。
 * 用法：node tools/assemble.mjs
 */
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..')
const dist = path.join(root, 'frontend', 'dist')
const site = path.join(root, 'site')

/**
 * 递归删除目录/文件。
 * 说明：Windows + 部分 Node 版本下 fs.rmSync(recursive) 会静默失败（不抛错也不删除），
 * 导致旧的 assets 残留、新旧 hash 文件混在一起；这里改为显式递归删除，行为可预期。
 */
function removePath(target) {
  if (!fs.existsSync(target)) {
    return
  }
  const st = fs.lstatSync(target)
  if (st.isDirectory()) {
    for (const name of fs.readdirSync(target)) {
      removePath(path.join(target, name))
    }
    fs.rmdirSync(target)
  } else {
    fs.unlinkSync(target)
  }
}

if (!fs.existsSync(dist)) {
  console.error('未找到 frontend/dist，请先执行 npm run build')
  process.exit(1)
}

const oldAssets = path.join(site, 'assets')
removePath(oldAssets)
const oldIndex = path.join(site, 'index.html')
removePath(oldIndex)

const newAssets = path.join(dist, 'assets')
if (fs.existsSync(newAssets)) {
  copyDir(newAssets, path.join(site, 'assets'))
}
fs.copyFileSync(path.join(dist, 'index.html'), path.join(site, 'index.html'))

/** 递归复制目录（不用 fs.cpSync：Windows + 部分 Node 版本会崩溃） */
function copyDir(src, dest) {
  fs.mkdirSync(dest, { recursive: true })
  for (const name of fs.readdirSync(src)) {
    const s = path.join(src, name)
    const d = path.join(dest, name)
    if (fs.statSync(s).isDirectory()) {
      copyDir(s, d)
    } else {
      fs.copyFileSync(s, d)
    }
  }
}

const uploadDir = path.join(site, 'uploads')
if (!fs.existsSync(uploadDir)) {
  fs.mkdirSync(uploadDir, { recursive: true })
}

function countFiles(dir) {
  let n = 0
  for (const name of fs.readdirSync(dir)) {
    const p = path.join(dir, name)
    if (fs.statSync(p).isDirectory()) {
      n += countFiles(p)
    } else {
      n += 1
    }
  }
  return n
}

console.log('组装完成: site/index.html + site/assets/')
console.log('静态产物文件数: ' + countFiles(path.join(site, 'assets')))
