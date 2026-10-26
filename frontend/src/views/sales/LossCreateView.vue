<script setup lang="ts">

/**
 * 输单登记页（销售人员填写）。
 * 业务员/部门自动带入登录人且只读（不支持代他人录入）；字典项来自「字典管理」。
 * 支持编辑：/sales/loss/create?id=xx 打开即为编辑态（本人或管理员）。
 */
import { computed, onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ElMessage, ElMessageBox, type FormInstance, type FormRules } from 'element-plus'
import { createLostOrder, getLostOrder, updateLostOrder, type LostOrderPayload } from '@/api/salesloss'
import { fetchWeatherToday } from '@/api/weather'
import { useAuthStore } from '@/stores/auth'
import { useDictStore } from '@/stores/dict'
import { useLossStore } from '@/stores/salesloss'
import { fmtDate } from '@/utils/format'
import type { WeatherToday } from '@/types'

const auth = useAuthStore()
const dict = useDictStore()
const lossStore = useLossStore()
const router = useRouter()
const route = useRoute()

const formRef = ref<FormInstance>()
const submitting = ref(false)
const loading = ref(false)

/** 今日天气（右侧「今天」卡片；拉不到就不显示，不影响登记） */
const weather = ref<WeatherToday | null>(null)

/** 今天：2026-10-23 星期五 */
const todayText = computed(() => {
  const d = new Date()
  const week = ['星期日', '星期一', '星期二', '星期三', '星期四', '星期五', '星期六'][d.getDay()]
  return `${fmtDate(d)} ${week}`
})

const showWeather = computed(() => weather.value?.available === true)

/** 城市展示名：省 + 市（如「山东滕州」）；省市重名时不重复 */
const weatherCity = computed(() => {
  const region = (weather.value?.region || '').trim()
  const city = (weather.value?.city || '').trim()
  if (!region) return city
  if (!city) return region
  return city.includes(region) ? city : region + city
})

/** 编辑态：URL 带 id 即编辑 */
const editId = computed(() => {
  const raw = route.query.id
  const n = Number(raw)
  return Number.isFinite(n) && n > 0 ? n : 0
})
const isEdit = computed(() => editId.value > 0)

/** 丢单时间默认当前时间（可手选，便于补录历史单） */
function nowText(): string {
  const d = new Date()
  const p = (n: number): string => (n < 10 ? `0${n}` : String(n))
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())} ${p(d.getHours())}:${p(d.getMinutes())}:00`
}

const form = reactive({
  customerName: '',
  lostAt: nowText(),
  lossTypeId: undefined as number | undefined,
  industryId: undefined as number | undefined,
  companyScaleId: undefined as number | undefined,
  lossStageId: undefined as number | undefined,
  budgetMatchId: undefined as number | undefined,
  remoteDemo: 0 as number,
  customized: 0 as number,
  finalQuote: null as number | null,
  customerChoice: '',
  reason: '',
})

const reasonLength = computed(() => form.reason.trim().length)
const noDepartment = computed(() => !auth.user?.departmentId)
const salesmanName = computed(() => auth.user?.name || auth.user?.account || '—')

const rules: FormRules = {
  // 客户名称：只做必填 + 长度限制，真实性靠页面提示（不拦截登记）
  customerName: [
    { required: true, message: '请填写客户企业名称', trigger: 'blur' },
    { max: 120, message: '客户名称最多 120 个字符', trigger: 'blur' },
  ],
  lostAt: [{ required: true, message: '请选择丢单时间', trigger: 'change' }],
  lossTypeId: [{ required: true, message: '请选择输单类型', trigger: 'change' }],
  industryId: [{ required: true, message: '请选择所属行业', trigger: 'change' }],
  companyScaleId: [{ required: true, message: '请选择企业规模', trigger: 'change' }],
  lossStageId: [{ required: true, message: '请选择丢单阶段', trigger: 'change' }],
  budgetMatchId: [{ required: true, message: '请选择预算匹配情况', trigger: 'change' }],
  reason: [
    { required: true, message: '请填写输单原因描述', trigger: 'blur' },
    {
      validator: (_r, value: string, cb) => {
        if (!value || !value.trim()) {
          cb(new Error('请填写输单原因描述'))
          return
        }
        cb()
      },
      trigger: 'blur',
    },
  ],
}

async function onSubmit(): Promise<void> {
  if (noDepartment.value) {
    ElMessage.error('请联系管理员分配部门')
    return
  }
  const valid = await formRef.value?.validate().catch(() => false)
  if (!valid) {
    return
  }
  if (!form.lossTypeId || !form.industryId || !form.companyScaleId || !form.lossStageId || !form.budgetMatchId) {
    return
  }
  await ElMessageBox.confirm(
    isEdit.value ? '确定保存本次修改吗？' : '提交后输单记录将进入销售分析统计，确定提交吗？',
    isEdit.value ? '确认保存' : '确认提交',
    { confirmButtonText: '确定', cancelButtonText: '再检查一下', type: 'info' },
  )
  submitting.value = true
  try {
    const payload: LostOrderPayload = {
      customerName: form.customerName.trim(),
      lostAt: form.lostAt,
      lossTypeId: form.lossTypeId,
      industryId: form.industryId,
      companyScaleId: form.companyScaleId,
      lossStageId: form.lossStageId,
      budgetMatchId: form.budgetMatchId,
      remoteDemo: form.remoteDemo,
      customized: form.customized,
      finalQuote: form.finalQuote === null || Number.isNaN(form.finalQuote) ? null : form.finalQuote,
      customerChoice: form.customerChoice.trim(),
      reason: form.reason.trim(),
    }
    if (isEdit.value) {
      const res = await updateLostOrder(editId.value, payload)
      lossStore.highlightId = res.id
      ElMessage.success('输单记录已更新')
    } else {
      const res = await createLostOrder(payload)
      lossStore.highlightId = res.id
      ElMessage.success('已保存，输单已登记')
    }
    await router.push('/sales/loss')
  } finally {
    submitting.value = false
  }
}

function onCancel(): void {
  void router.back()
}

/** 编辑态：回填原记录（字典 ID 已随记录落库，理论上都有值） */
async function loadDetail(): Promise<void> {
  if (!isEdit.value) {
    return
  }
  loading.value = true
  try {
    const r = await getLostOrder(editId.value)
    form.customerName = r.customerName
    form.lostAt = r.lostAt
    form.lossTypeId = r.lossTypeId ?? undefined
    form.industryId = r.industryId ?? undefined
    form.companyScaleId = r.companyScaleId ?? undefined
    form.lossStageId = r.lossStageId ?? undefined
    form.budgetMatchId = r.budgetMatchId ?? undefined
    form.remoteDemo = r.remoteDemo
    form.customized = r.customized
    form.finalQuote = r.finalQuote
    form.customerChoice = r.customerChoice
    form.reason = r.reason
  } catch {
    // 失败（无权限/已删除）由请求层提示
  } finally {
    loading.value = false
  }
}

/** 今日天气：失败静默（天气只是锦上添花，绝不能挡住登记） */
async function loadWeather(): Promise<void> {
  try {
    weather.value = await fetchWeatherToday()
  } catch {
    weather.value = null
  }
}

onMounted(async () => {
  void loadWeather()
  await dict.load()
  await loadDetail()
})
</script>

<template>
  <div class="page">
    <div v-if="noDepartment" class="scope-notice">
      <span aria-hidden="true">!</span>
      当前账号尚未分配部门，请联系管理员分配部门后再登记输单。
    </div>

    <div class="create-layout">
      <div v-loading="loading" class="moon-card moon-card--moonlit">
        <div class="form-head">
          <div class="form-head__title">{{ isEdit ? '编辑输单' : '输单登记' }}</div>
          <div class="form-head__tip">带 * 为必填；客户名称可填企业名或联系电话</div>
        </div>

        <el-form ref="formRef" :model="form" :rules="rules" label-width="104px" label-position="right">
          <el-form-item label="业务员">
            <div class="readonly-field">{{ salesmanName }}</div>
          </el-form-item>

          <el-form-item label="部门">
            <div class="readonly-field">{{ auth.user?.departmentName || '未分配部门（请联系管理员分配）' }}</div>
          </el-form-item>

          <el-form-item label="客户名称" prop="customerName">
            <el-input
              v-model="form.customerName"
              maxlength="120"
              placeholder="填客户企业全称，如：永康市谦益工贸有限公司"
              style="width: 360px"
            />
            <div class="form-tip form-tip--warn">
              请填写<strong>真实的客户企业名称</strong>（不要填电话号码、联系人姓名或「不详」），否则客户维度分析会失真
            </div>
          </el-form-item>

          <el-form-item label="丢单时间" prop="lostAt">
            <el-date-picker
              v-model="form.lostAt"
              type="datetime"
              placeholder="选择丢单时间"
              value-format="YYYY-MM-DD HH:mm:ss"
              :default-value="new Date()"
              style="width: 260px"
            />
          </el-form-item>

          <el-form-item label="输单类型" prop="lossTypeId">
            <el-select v-model="form.lossTypeId" placeholder="请选择输单类型" style="width: 260px">
              <el-option v-for="it in dict.enabledOf('loss_type')" :key="it.id" :label="it.name" :value="it.id" />
            </el-select>
          </el-form-item>

          <el-form-item label="丢单阶段" prop="lossStageId">
            <el-select v-model="form.lossStageId" placeholder="请选择丢单阶段" style="width: 260px">
              <el-option v-for="it in dict.enabledOf('loss_stage')" :key="it.id" :label="it.name" :value="it.id" />
            </el-select>
          </el-form-item>

          <el-form-item label="所属行业" prop="industryId">
            <el-select v-model="form.industryId" placeholder="请选择所属行业" style="width: 260px">
              <el-option v-for="it in dict.enabledOf('industry')" :key="it.id" :label="it.name" :value="it.id" />
            </el-select>
          </el-form-item>

          <el-form-item label="企业规模" prop="companyScaleId">
            <el-select v-model="form.companyScaleId" placeholder="请选择企业规模" style="width: 260px">
              <el-option v-for="it in dict.enabledOf('company_scale')" :key="it.id" :label="it.name" :value="it.id" />
            </el-select>
          </el-form-item>

          <el-form-item label="是否远程演示">
            <el-radio-group v-model="form.remoteDemo">
              <el-radio :value="1">是</el-radio>
              <el-radio :value="0">否</el-radio>
            </el-radio-group>
          </el-form-item>

          <el-form-item label="最终报价">
            <el-input-number v-model="form.finalQuote" :min="0" :max="9999999999" :step="1000" :precision="2" controls-position="right" style="width: 220px" />
            <span class="form-tip form-tip--inline">元；未报价可留空</span>
          </el-form-item>

          <el-form-item label="是否定制化">
            <el-radio-group v-model="form.customized">
              <el-radio :value="1">是</el-radio>
              <el-radio :value="0">否</el-radio>
            </el-radio-group>
          </el-form-item>

          <el-form-item label="预算匹配" prop="budgetMatchId">
            <el-select v-model="form.budgetMatchId" placeholder="请选择预算匹配情况" style="width: 260px">
              <el-option v-for="it in dict.enabledOf('budget_match')" :key="it.id" :label="it.name" :value="it.id" />
            </el-select>
          </el-form-item>

          <el-form-item label="客户最终选择">
            <el-input v-model="form.customerChoice" maxlength="500" placeholder="如：竞品年费方案 / 后续联系不上 / 不详" style="width: 360px" />
          </el-form-item>

          <el-form-item label="输单原因" prop="reason">
            <el-input
              v-model="form.reason"
              type="textarea"
              :rows="6"
              maxlength="2000"
              show-word-limit
              placeholder="请描述：客户诉求、我方方案与报价、卡在哪一步、客户最后为什么没选我们"
            />
            <div class="form-tip">已输入 {{ reasonLength }} 字</div>
          </el-form-item>

          <el-form-item label=" ">
            <div class="form-actions">
              <el-button @click="onCancel">取消</el-button>
              <el-button type="primary" :loading="submitting" @click="onSubmit">
                {{ isEdit ? '保存修改' : '提交' }}
              </el-button>
            </div>
          </el-form-item>
        </el-form>
      </div>

      <div class="create-side">
        <div class="moon-card moon-card--moonlit side-card">
          <div class="side-card__title">登记小贴士</div>
          <ul class="side-card__list">
            <li>丢单时间按<strong>实际丢单那天</strong>填写，补录历史单也能对齐统计口径。</li>
            <li>输单类型只能选一项，选<strong>最主要</strong>的那个原因。</li>
            <li>已经远程演示过仍丢单的，务必勾「远程演示 = 是」，分析页会单独对比。</li>
            <li>原因描述写清「客户诉求 → 我方动作 → 卡点」，才能反哺产品和报价策略。</li>
          </ul>
        </div>
        <div class="moon-card side-card side-today">
          <div class="side-card__title">今天</div>
          <div class="side-card__date">{{ todayText }}</div>

          <!-- 天气：城市取 .env 的 WEATHER_CITY，服务端代理并缓存 30 分钟 -->
          <template v-if="showWeather">
            <div class="side-weather">
              <span class="side-weather__icon" aria-hidden="true">{{ weather?.icon }}</span>
              <span class="side-weather__temp">{{ weather?.temp }}<i>°C</i></span>
              <span class="side-weather__text">{{ weather?.text }}</span>
            </div>
            <div class="side-weather__meta">
              <span>{{ weatherCity }}</span>
              <span>最高 {{ weather?.high }}° / 最低 {{ weather?.low }}°</span>
            </div>
            <div class="side-weather__sub">
              <span>体感 {{ weather?.feelsLike }}°</span>
              <span>湿度 {{ weather?.humidity }}%</span>
              <span>风 {{ weather?.wind }} km/h</span>
            </div>
          </template>

          <div class="side-card__tip">登记后可在「输单列表」查看与修改（管理员可删除）。</div>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
/* 左右两栏：左表单（主）+ 右侧贴士；窄屏自动堆叠 */
.create-layout {
  display: grid;
  grid-template-columns: minmax(0, 1fr) 320px;
  gap: var(--space-6);
  align-items: start;
}

.form-head {
  display: flex;
  flex-direction: column;
  gap: 4px;
  margin-bottom: var(--space-4);
}

.form-head__title {
  font-size: 16px;
  font-weight: 700;
  color: var(--color-text-primary);
}

.form-head__tip {
  font-size: 12px;
  color: var(--color-text-secondary);
}

.form-actions {
  display: flex;
  gap: 8px;
}

.create-side {
  display: flex;
  flex-direction: column;
  gap: var(--space-4);
}

.side-card {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.side-card__title {
  font-size: 13px;
  font-weight: 600;
  color: var(--color-text-primary);
}

.side-card__list {
  margin: 0;
  padding-left: 18px;
  display: flex;
  flex-direction: column;
  gap: 6px;
  font-size: 12px;
  line-height: 1.7;
  color: var(--color-text-secondary);
}

.side-card__date {
  font-size: 20px;
  font-weight: 700;
  color: var(--color-primary);
  font-variant-numeric: tabular-nums;
}

.side-card__tip {
  font-size: 12px;
  color: var(--color-text-secondary);
}

/* 「今天」卡片：日期 + 天气 */
.side-today {
  gap: 10px;
}

.side-weather {
  display: flex;
  align-items: baseline;
  gap: 8px;
}

.side-weather__icon {
  font-size: 26px;
  line-height: 1;
}

.side-weather__temp {
  font-size: 30px;
  font-weight: 700;
  color: var(--color-text-primary);
  font-variant-numeric: tabular-nums;
  line-height: 1;
}

.side-weather__temp i {
  font-size: 14px;
  font-style: normal;
  font-weight: 500;
  color: var(--color-text-secondary);
  margin-left: 2px;
}

.side-weather__text {
  font-size: 13px;
  color: var(--color-text-regular);
}

.side-weather__meta {
  display: flex;
  flex-wrap: wrap;
  gap: 4px 10px;
  font-size: 12px;
  color: var(--color-text-regular);
}

.side-weather__sub {
  display: flex;
  flex-wrap: wrap;
  gap: 4px 10px;
  font-size: 12px;
  color: var(--color-text-secondary);
}

.form-tip--inline {
  margin-left: 8px;
}

/* 客户名称提示：比普通说明稍醒目一点（只提示，不拦截提交） */
.form-tip--warn {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  color: var(--color-primary);
}

@media (max-width: 1180px) {
  .create-layout {
    grid-template-columns: minmax(0, 1fr);
    gap: var(--space-4);
  }
}
</style>
