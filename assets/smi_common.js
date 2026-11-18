// ============================================================
// smi_common.js — JavaScript ร่วมสำหรับระบบ SMI-V
// ============================================================
const smiCommon = (() => {
  // คำนวณระดับความเสี่ยงจากคะแนน OAS/PVSS/BPRS/8Q (ตัวอย่างต้นแบบ — ปรับตามมาตรฐานกระทรวงฯ ต่อไป)
  function calcRisk() {
    const oas = parseInt(document.getElementById('oas_score')?.value) || 0;
    const pvss = parseInt(document.getElementById('pvss_score')?.value) || 0;
    const bprs = parseInt(document.getElementById('bprs_score')?.value) || 0;
    const q8q = parseInt(document.getElementById('q8q_score')?.value) || 0;

    // ตัวอย่างเกณฑ์คร่าวๆ (ต้องปรับตามเอกสารมาตรฐานกระทรวงสาธารณสุข)
    let score = oas + pvss + (bprs/10) + q8q;
    let level = 'yellow';
    let freq = '14_days';

    if (score >= 80 || bprs >= 60 || q8q >= 6) {
      level = 'red';
      freq = '7_days';
    } else if (score >= 50 || bprs >= 40 || q8q >= 4) {
      level = 'yellow';
      freq = '14_days';
    } else {
      level = 'green';
      freq = '30_days';
    }

    const box = document.getElementById('resultBox');
    if (box) {
      box.style.display = 'block';
      box.className = 'result-box ' + level;
      box.innerHTML = `
        <strong>ผลการคำนวณ:</strong> คะแนนรวมประมาณ ${score.toFixed(1)}<br>
        <strong>ระดับความเสี่ยง:</strong> ${level.toUpperCase()}<br>
        <strong>ความถี่เยี่ยมบ้าน:</strong> ${freq === '7_days' ? 'ภายใน 7 วัน' : freq === '14_days' ? 'ภายใน 14 วัน' : 'ภายใน 1 เดือน'}
      `;
    }
    return { score, level, freq };
  }

  // ตรวจสอบรหัสบัตรประชาชน 13 หลัก
  function validIDCard(idcard) {
    return /^\d{13}$/.test(idcard);
  }

  // แสดงข้อความแจ้งเตือนแบบเรียบง่าย
  function showAlert(msg, type = 'info') {
    const box = document.createElement('div');
    box.className = 'alert ' + type;
    box.textContent = msg;
    box.style.padding = '12px';
    box.style.borderRadius = '6px';
    box.style.marginBottom = '15px';
    box.style.color = type === 'error' ? '#7f1d1d' : '#065f46';
    box.style.background = type === 'error' ? '#fee2e2' : '#d1fae5';
    box.style.border = type === 'error' ? '1px solid #dc2626' : '1px solid #059669';
    document.body.insertBefore(box, document.body.firstChild);
    setTimeout(() => box.remove(), 4000);
  }

  return { calcRisk, validIDCard, showAlert };
})();

// ตัวแปร global เพื่อให้หน้า HTML เรียกใช้ได้ง่าย
window.smiCommon = smiCommon;