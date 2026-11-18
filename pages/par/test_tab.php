<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ทดสอบ Tab</title>
</head>
<body>

<h1>ทดสอบ Tab แบบง่าย</h1>

<!-- ปุ่ม Tab -->
<button id="btn1" style="padding:10px; margin:5px; cursor:pointer; background:#ddd;">Tab 1</button>
<button id="btn2" style="padding:10px; margin:5px; cursor:pointer; background:#ddd;">Tab 2</button>
<button id="btn3" style="padding:10px; margin:5px; cursor:pointer; background:#ddd;">Tab 3</button>

<!-- เนื้อหา Tab -->
<div id="content1" style="padding:20px; background:#f0f0f0; display:block;">เนื้อหา Tab 1</div>
<div id="content2" style="padding:20px; background:#e0e0e0; display:none;">เนื้อหา Tab 2</div>
<div id="content3" style="padding:20px; background:#d0d0d0; display:none;">เนื้อหา Tab 3</div>

<script>
// ทดสอบว่า JavaScript ทำงานหรือไม่
alert('JavaScript ทำงานแล้ว!');

// ฟังก์ชันสลับ Tab
function showTab(tabNumber) {
    alert('กำลังเปิด Tab: ' + tabNumber);
    
    // ซ่อนทุก content
    document.getElementById('content1').style.display = 'none';
    document.getElementById('content2').style.display = 'none';
    document.getElementById('content3').style.display = 'none';
    
    // แสดง content ที่เลือก
    document.getElementById('content' + tabNumber).style.display = 'block';
}

// ผูก event โดยตรง
document.getElementById('btn1').onclick = function() { showTab(1); };
document.getElementById('btn2').onclick = function() { showTab(2); };
document.getElementById('btn3').onclick = function() { showTab(3); };

// ตั้งค่าเริ่มต้น
document.getElementById('btn1').style.background = '#4CAF50';
document.getElementById('btn1').style.color = 'white';
</script>

</body>
</html>