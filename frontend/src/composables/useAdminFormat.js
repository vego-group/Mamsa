import { useAdminI18n } from '@/i18n/admin'

/**
 * Shared number / money / date formatting for admin screens.
 * Numbers stay Latin (the design uses Latin digits); dates follow the locale.
 */
const nfInt = new Intl.NumberFormat('en-US')
const nfCompact = new Intl.NumberFormat('en-US', { notation: 'compact', maximumFractionDigits: 1 })

export function useAdminFormat() {
  const { t, locale } = useAdminI18n()

  const int = (v) => nfInt.format(Number(v) || 0)
  const compact = (v) => nfCompact.format(Number(v) || 0)
  const grouped = (v) => nfInt.format(Math.round(Number(v) || 0))
  // "18,450 SAR" — grouped for readability like the design's table cells.
  const sar = (v) => `${grouped(v)} ${t('common.sar')}`
  // "487K SAR" — compact for headline KPI numbers.
  const sarCompact = (v) => `${compact(v)} ${t('common.sar')}`

  function date(iso, opts = { day: '2-digit', month: 'short', year: 'numeric' }) {
    if (!iso) return '—'
    return new Intl.DateTimeFormat(locale.value === 'ar' ? 'ar' : 'en', opts).format(new Date(iso))
  }

  return { int, compact, grouped, sar, sarCompact, date }
}
