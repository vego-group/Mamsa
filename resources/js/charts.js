// استيراد Chart.js وتسجيل العناصر الأساسية
import {
  Chart,
  BarElement,
  CategoryScale,
  LinearScale,
  Tooltip,
  Legend,
} from 'chart.js';

Chart.register(BarElement, CategoryScale, LinearScale, Tooltip, Legend);

// دالة Helper لرسم عمود
function renderBarChart(canvasId, labels, data, color = '#2f4b46') {
  const el = document.getElementById(canvasId);
  if (!el) return;

  new Chart(el, {
    type: 'bar',
    data: {
      labels,
      datasets: [{
        data,
        backgroundColor: color + 'CC',
        borderColor: color,
        borderWidth: 1.5,
        borderRadius: 8,
      }]
    },
    options: {
      scales: { y: { beginAtZero: true, ticks: { precision: 0 } } },
      plugins: { legend: { display: false } }
    }
  });
}

// شغّل فقط في صفحات فيها الكانفاسات
document.addEventListener('DOMContentLoaded', () => {
  // القيم تُحقن من Blade إلى window (شوفي الخطوة 3)
  if (window.__DASHBOARD__) {
    const { revLabels, revDataset, bookLabels, bookDataset } = window.__DASHBOARD__;
    renderBarChart('revenueChart', revLabels, revDataset);
    renderBarChart('bookingsChart', bookLabels, bookDataset);
  }
});