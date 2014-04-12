
function fnEdit(customer_id) {
  document.form1.action = '/admin/customer/edit.php';
  document.form1.mode.value = "edit";
  document.form1['customer_id'].value = customer_id;
  document.form1.submit();
  return false;
}

function fnCopyFromOrderData() {
  df = document.form1;

  // お届け先名のinputタグのnameを取得
  var shipping_data = $('input[name^=shipping_name01]').attr('name');
  var shipping_slt = shipping_data.split("shipping_name01");

  var shipping_key = "[0]";
  if (shipping_slt.length > 1) {
    shipping_key = shipping_slt[1];
  }

  df['shipping_name01' + shipping_key].value = df.order_name01.value;
  df['shipping_name02' + shipping_key].value = df.order_name02.value;
  df['shipping_kana01' + shipping_key].value = df.order_kana01.value;
  df['shipping_kana02' + shipping_key].value = df.order_kana02.value;
  df['shipping_zip01' + shipping_key].value = df.order_zip01.value;
  df['shipping_zip02' + shipping_key].value = df.order_zip02.value;
  df['shipping_tel01' + shipping_key].value = df.order_tel01.value;
  df['shipping_tel02' + shipping_key].value = df.order_tel02.value;
  df['shipping_tel03' + shipping_key].value = df.order_tel03.value;
  df['shipping_fax01' + shipping_key].value = df.order_fax01.value;
  df['shipping_fax02' + shipping_key].value = df.order_fax02.value;
  df['shipping_fax03' + shipping_key].value = df.order_fax03.value;
  df['shipping_addr01' + shipping_key].value = df.order_addr01.value;
  df['shipping_addr02' + shipping_key].value = df.order_addr02.value;
}

function fnFormConfirm() {
  if (fnConfirm()) {
    document.form1.submit();
  }
}

function fnMultiple() {
  win03('/admin/order/multiple.php', 'multiple', '600', '500');
  document.form1.anchor_key.value = "shipping";
  document.form1.mode.value = "multiple";
  document.form1.submit();
  return false;
}

function fnAppendShipping() {
  document.form1.anchor_key.value = "shipping";
  document.form1.mode.value = "append_shipping";
  document.form1.submit();
  return false;
}