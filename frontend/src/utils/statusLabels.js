export function formatStatusLabel(value, fallback = "Open") {
  const raw = String(value || fallback || "").trim();

  if (!raw) return "";

  return raw
    .replaceAll("_", " ")
    .replace(/\s+/g, " ")
    .replace(/\b\w/g, (letter) => letter.toUpperCase());
}

export function formatSentenceStatus(value, fallback = "open") {
  const label = formatStatusLabel(value, fallback);

  if (!label) return "";

  return label.charAt(0).toUpperCase() + label.slice(1).toLowerCase();
}
