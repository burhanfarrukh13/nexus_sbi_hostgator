import {
  Fragment,
  useEffect,
  useRef,
  useState,
  type ComponentProps,
  type FormEvent,
  type ReactNode,
} from "react";
import {
  ArrowLeft,
  ArrowRight,
  BarChart3,
  BookOpen,
  Check,
  ChevronLeft,
  ChevronRight,
  CircleHelp,
  Clock3,
  Download,
  FileText,
  Folder,
  GraduationCap,
  Bold,
  Italic,
  Underline,
  AlignLeft,
  AlignCenter,
  AlignRight,
  LayoutDashboard,
  LogOut,
  Menu,
  MessageCircle,
  Pencil,
  Plus,
  Search,
  Send,
  Settings,
  ShieldCheck,
  Sparkles,
  Trash2,
  Trophy,
  Users,
  X,
} from "lucide-react";
import { getDocument, GlobalWorkerOptions } from "pdfjs-dist";
import pdfWorkerUrl from "pdfjs-dist/build/pdf.worker.min.mjs?url";
import {
  API_BASE,
  api,
  ApiError,
  asArray,
  getToken,
  getTokenSubject,
  json,
  label,
  setSessionTokens,
  setToken,
} from "./api";

GlobalWorkerOptions.workerSrc = pdfWorkerUrl;

const MAX_ANSWER_PDF_PAGES = 30;
const MAX_VISION_IMAGE_WIDTH = 1400;
const MAX_VISION_IMAGE_BYTES = 350 * 1024;
// Keep status checks light and do not poll faster than the server's suggested
// interval or the 20-second student-safe minimum.
const GRADING_FAST_POLLS = 6;

function canvasToJpeg(canvas: HTMLCanvasElement, quality: number): Promise<Blob> {
  return new Promise((resolve, reject) => {
    canvas.toBlob(
      (blob) =>
        blob
          ? resolve(blob)
          : reject(new Error("Could not encode an answer image.")),
      "image/jpeg",
      quality,
    );
  });
}

async function renderAnswerPdf(file: File, prefix: string): Promise<File[]> {
  const pdf = await getDocument({
    data: new Uint8Array(await file.arrayBuffer()),
  }).promise;
  try {
    if (pdf.numPages < 1 || pdf.numPages > MAX_ANSWER_PDF_PAGES) {
      throw new Error(
        `Answer PDFs must contain 1 to ${MAX_ANSWER_PDF_PAGES} pages.`,
      );
    }

    const pages: File[] = [];
    for (let pageNumber = 1; pageNumber <= pdf.numPages; pageNumber += 1) {
      const page = await pdf.getPage(pageNumber);
      const originalViewport = page.getViewport({ scale: 1 });
      const viewport = page.getViewport({
        scale: Math.min(1, MAX_VISION_IMAGE_WIDTH / originalViewport.width),
      });
      const canvas = window.document.createElement("canvas");
      canvas.width = Math.max(1, Math.round(viewport.width));
      canvas.height = Math.max(1, Math.round(viewport.height));
      const context = canvas.getContext("2d");
      if (!context) throw new Error("Could not prepare an answer PDF page.");
      await page.render({ canvasContext: context, viewport }).promise;

      let blob = await canvasToJpeg(canvas, 0.8);
      let quality = 0.7;
      while (blob.size > MAX_VISION_IMAGE_BYTES && quality >= 0.5) {
        blob = await canvasToJpeg(canvas, quality);
        quality -= 0.1;
      }
      if (blob.size > MAX_VISION_IMAGE_BYTES) {
        throw new Error(
          "A rendered PDF page is too large to submit. Use a lower-resolution scan.",
        );
      }
      pages.push(
        new File([blob], `${prefix}-page-${pageNumber}.jpg`, {
          type: "image/jpeg",
        }),
      );
      canvas.width = 0;
      canvas.height = 0;
      page.cleanup();
    }
    return pages;
  } finally {
    await pdf.destroy();
  }
}

async function compactAnswerImage(file: File, prefix: string): Promise<File> {
  const image = await createImageBitmap(file);
  try {
    if (
      image.width <= MAX_VISION_IMAGE_WIDTH &&
      file.size <= MAX_VISION_IMAGE_BYTES
    ) {
      return new File([file], `${prefix}.${file.name.split(".").pop()}`, {
        type: file.type,
      });
    }

    const scale = Math.min(1, MAX_VISION_IMAGE_WIDTH / image.width);
    const canvas = window.document.createElement("canvas");
    canvas.width = Math.max(1, Math.round(image.width * scale));
    canvas.height = Math.max(1, Math.round(image.height * scale));
    const context = canvas.getContext("2d");
    if (!context) throw new Error("Could not prepare the answer image.");
    context.drawImage(image, 0, 0, canvas.width, canvas.height);
    let blob = await canvasToJpeg(canvas, 0.8);
    let quality = 0.7;
    while (blob.size > MAX_VISION_IMAGE_BYTES && quality >= 0.5) {
      blob = await canvasToJpeg(canvas, quality);
      quality -= 0.1;
    }
    if (blob.size > MAX_VISION_IMAGE_BYTES) {
      throw new Error(
        "An answer image is too large to submit. Use a lower-resolution photo.",
      );
    }
    canvas.width = 0;
    canvas.height = 0;
    return new File([blob], `${prefix}.jpg`, { type: "image/jpeg" });
  } finally {
    image.close();
  }
}

async function appendAnswerFile(
  form: FormData,
  file: File,
  prefix: string,
): Promise<void> {
  if (file.name.toLowerCase().endsWith(".pdf")) {
    if (prefix === "paper") {
      const safeName =
        file.name.replace(/[^A-Za-z0-9._-]+/g, "-").replace(/^\.+/, "").slice(0, 180) ||
        "submission.pdf";
      form.append("files[]", file, `paper-source-${safeName}`);
    }
    for (const page of await renderAnswerPdf(file, prefix)) {
      form.append("files[]", page, page.name);
    }
    return;
  }
  if (file.type.startsWith("image/")) {
    const image = await compactAnswerImage(file, prefix);
    form.append("files[]", image, image.name);
    return;
  }
  const extension = file.name.split(".").pop()?.toLowerCase() ?? "";
  form.append("files[]", file, `${prefix}.${extension}`);
}
import {
  clearTestAssetCache,
  getTestPageBlob,
  getTestPageCount,
  setTestAssetCacheUser,
} from "./test-asset-cache";
import type { jsPDF } from "jspdf";
import "./styles.css";

type Data = Record<string, any>;
type QuestionDraft = {
  questionText: string;
  idealAnswer: string;
  difficulty: "EASY" | "MODERATE" | "DIFFICULT";
  markingText: string;
  questionSourceLocations?: Array<{
    sourceIndex: number;
    pageNumbers: number[];
  }>;
  solutionSourceLocations?: Array<{
    sourceIndex: number;
    pageNumbers: number[];
  }>;
};
type Person = {
  id: string;
  email?: string;
  fullName?: string;
  role?: string;
  studentId?: string;
  adminPermissions?: string[];
  mustResetPassword?: boolean;
};
type Page =
  | "dashboard"
  | "notebook"
  | "tests"
  | "performers"
  | "contact"
  | "academic"
  | "team"
  | "test-admin"
  | "settings"
  | "access-requests"
  | "students"
  | "users"
  | "passwords"
  | "my-password"
  | "queries"
  | "analytics";
const studentLinks: {
  id: Page;
  title: string;
  icon: typeof LayoutDashboard;
}[] = [
  { id: "dashboard", title: "Dashboard", icon: LayoutDashboard },
  { id: "notebook", title: "Notebook", icon: BookOpen },
  { id: "tests", title: "My Tests", icon: FileText },
  { id: "performers", title: "Top Performers", icon: Trophy },
  { id: "contact", title: "Contact Support", icon: MessageCircle },
  { id: "my-password", title: "My Password", icon: ShieldCheck },
];
const adminLinks: { id: Page; title: string; icon: typeof LayoutDashboard }[] =
  [
    { id: "academic", title: "Subjects", icon: BookOpen },
    { id: "team", title: "Team Members", icon: Users },
    { id: "test-admin", title: "Tests & Marks", icon: FileText },
    { id: "settings", title: "Settings", icon: Settings },
    { id: "access-requests", title: "Access Requests", icon: Clock3 },
    { id: "students", title: "Students", icon: Users },
    { id: "users", title: "Users", icon: ShieldCheck },
    { id: "my-password", title: "My Password", icon: ShieldCheck },
    { id: "passwords", title: "Manage Passwords", icon: ShieldCheck },
    { id: "notebook", title: "AI Notebook", icon: BookOpen },
    { id: "queries", title: "Student Queries", icon: MessageCircle },
    { id: "analytics", title: "Analytics", icon: BarChart3 },
  ];
const adminPermissionOptions = [
  ["admin", "Super admin (all permissions)"],
  ["teacher_assistant", "Tests and notebook content"],
  ["user_manager", "User access"],
  ["password_manager", "Passwords"],
  ["testing_manager", "Test access and marks"],
  ["student_query", "Student query inbox"],
] as const;
const getId = (x: Data) => String(x.id ?? x.uuid ?? "");
const getTitle = (x: Data) =>
  String(x.title ?? x.name ?? x.fullName ?? x.email ?? "Untitled");
const formatDate = (x: unknown) => {
  if (!x) return "—";
  const d = new Date(String(x));
  return Number.isNaN(d.getTime())
    ? String(x)
    : d.toLocaleDateString(undefined, {
        month: "short",
        day: "numeric",
        year: "numeric",
      });
};
const formatDateTime = (x: unknown) => {
  if (!x) return "—";
  const d = new Date(String(x));
  return Number.isNaN(d.getTime())
    ? String(x)
    : d.toLocaleString(undefined, {
        month: "short",
        day: "numeric",
        year: "numeric",
        hour: "numeric",
        minute: "2-digit",
      });
};
function splitMarkdownTableRow(line: string): string[] | null {
  const trimmed = line.trim();
  if (!trimmed.includes("|")) return null;
  const cells: string[] = [];
  let cell = "";
  for (let index = 0; index < trimmed.length; index += 1) {
    const character = trimmed[index];
    if (character === "\\" && trimmed[index + 1] === "|") {
      cell += "|";
      index += 1;
    } else if (character === "|") {
      cells.push(cell.trim());
      cell = "";
    } else {
      cell += character;
    }
  }
  cells.push(cell.trim());
  if (trimmed.startsWith("|")) cells.shift();
  if (trimmed.endsWith("|") && cells[cells.length - 1] === "") cells.pop();
  return cells;
}

function tableCellForMarkdown(value: string): string {
  return value.replace(/\|/g, "\\|").replace(/\s+/g, " ").trim();
}

function questionTextForPdf(content: string): string {
  let normalized = content;
  if (/&lt;\s*table\b/i.test(normalized)) {
    normalized =
      new DOMParser().parseFromString(normalized, "text/html").documentElement
        .textContent ?? normalized;
  }
  if (/<table\b/i.test(normalized)) {
    const parsed = new DOMParser().parseFromString(normalized, "text/html");
    parsed.querySelectorAll("script, style").forEach((node) => node.remove());
    parsed.querySelectorAll("table").forEach((table) => {
      const rows = Array.from(table.querySelectorAll("tr"))
        .map((row) =>
          Array.from(row.querySelectorAll("th, td")).map((cell) =>
            tableCellForMarkdown(cell.textContent ?? ""),
          ),
        )
        .filter((row) => row.some(Boolean));
      const columnCount = Math.max(0, ...rows.map((row) => row.length));
      if (!columnCount) {
        table.remove();
        return;
      }
      const paddedRows = rows.map((row) =>
        Array.from({ length: columnCount }, (_, index) => row[index] ?? ""),
      );
      const markdown = [
        `| ${paddedRows[0].join(" | ")} |`,
        `| ${paddedRows[0].map(() => "---").join(" | ")} |`,
        ...paddedRows.slice(1).map((row) => `| ${row.join(" | ")} |`),
      ].join("\n");
      table.replaceWith(parsed.createTextNode(`\n${markdown}\n`));
    });

    const extractText = (node: Node): string => {
      if (node.nodeType === Node.TEXT_NODE) return node.textContent ?? "";
      const tagName = node instanceof Element ? node.tagName : "";
      if (tagName === "BR") return "\n";
      const text = Array.from(node.childNodes, extractText).join("");
      return /^(P|DIV|LI|H[1-6]|TR|SECTION|ARTICLE)$/.test(tagName)
        ? `\n${text}\n`
        : text;
    };
    normalized = extractText(parsed.body).replace(/\n{3,}/g, "\n\n").trim();
  }

  const lines = normalizeJournalTables(normalized.replace(/\r\n?/g, "\n").split("\n"));
  const output: string[] = [];
  for (let index = 0; index < lines.length; index += 1) {
    const firstRow =
      splitTabDelimitedRow(lines[index]) ??
      splitAlignedTableRow(lines[index]) ??
      splitFinancialTableRow(lines[index]);
    if (!firstRow) {
      output.push(lines[index]);
      continue;
    }
    const rows = [firstRow];
    let nextIndex = index + 1;
    while (nextIndex < lines.length) {
      const row =
        splitTabDelimitedRow(lines[nextIndex]) ??
        splitAlignedTableRow(lines[nextIndex]) ??
        splitFinancialTableRow(lines[nextIndex]);
      if (!row) break;
      rows.push(row);
      nextIndex += 1;
    }
    const dataRows = rows.filter(
      (row) => !row.every((cell) => /^:?-{3,}:?$/.test(cell)),
    );
    if (dataRows.length < 2) {
      output.push(lines[index]);
      continue;
    }
    const columnCount = Math.max(...dataRows.map((row) => row.length));
    const paddedRows = dataRows.map((row) =>
      Array.from({ length: columnCount }, (_, cellIndex) => row[cellIndex] ?? ""),
    );
    output.push(
      `| ${paddedRows[0].map(tableCellForMarkdown).join(" | ")} |`,
      `| ${paddedRows[0].map(() => "---").join(" | ")} |`,
      ...paddedRows
        .slice(1)
        .map((row) => `| ${row.map(tableCellForMarkdown).join(" | ")} |`),
    );
    index = nextIndex - 1;
  }
  return output.join("\n");
}

function splitAlignedTableRow(line: string): string[] | null {
  if (!/\S {2,}\S/.test(line)) return null;
  const cells = line.trim().split(/ {2,}/).map((cell) => cell.trim());
  return cells.length >= 2 && cells.length <= 12 ? cells : null;
}

function splitFinancialTableRow(line: string): string[] | null {
  const cell = String.raw`(?:\(?-?\d[\d,]*(?:\.\d+)?\)?|[-–—])`;
  const match = line.trim().match(
    new RegExp(`^(.*?)\\s+(${cell}(?:\\s+${cell}){2,})$`),
  );
  if (!match) return null;
  const values = match[2].trim().split(/\s+/);
  if (values.length < 3) return null;
  const labelText = match[1].trim();
  return labelText ? [labelText, ...values] : values;
}

function normalizeJournalTables(lines: string[]): string[] {
  const output: string[] = [];
  for (let index = 0; index < lines.length; index += 1) {
    const header = lines[index].trim();
    if (
      !/\bdate\b/i.test(header) ||
      !/\bdetails?\b/i.test(header) ||
      !/\bamount\b/i.test(header) ||
      !/\bdr\b/i.test(header) ||
      !/\bcr\b/i.test(header)
    ) {
      output.push(lines[index]);
      continue;
    }

    const rows: string[][] = [];
    let cursor = index + 1;
    let incompleteRowStart: number | null = null;
    while (cursor < lines.length) {
      const rowStart = cursor;
      const dateMatch = lines[cursor]
        .trim()
        .match(/^(\d{1,2}[-/](?:[a-z]{3}|\d{1,2})[-/]\d{2,4})(?:\s+(.*))?$/i);
      if (!dateMatch) break;
      incompleteRowStart = rowStart;
      const date = dateMatch[1];
      const details = dateMatch[2] ? [dateMatch[2].trim()] : [];
      const amounts: string[] = [];
      cursor += 1;
      while (cursor < lines.length && amounts.length < 2) {
        const line = lines[cursor].trim();
        if (/^\d{1,2}[-/](?:[a-z]{3}|\d{1,2})[-/]\d{2,4}(?:\s|$)/i.test(line)) {
          break;
        }
        if (/^\(?-?\d[\d,]*(?:\.\d+)?\)?$/.test(line)) {
          amounts.push(line);
        } else if (line) {
          details.push(line);
        }
        cursor += 1;
      }
      if (amounts.length !== 2 || details.length === 0) break;
      rows.push([date, details.join(" "), ...amounts]);
      incompleteRowStart = null;
    }

    if (rows.length < 2) {
      output.push(lines[index]);
      continue;
    }
    output.push(
      "| Date | Details | Amount Rs Dr | Amount Rs Cr |",
      "| --- | --- | ---: | ---: |",
      ...rows.map((row) => `| ${row.map(tableCellForMarkdown).join(" | ")} |`),
    );
    index = (incompleteRowStart ?? cursor) - 1;
  }
  return output;
}

function splitTabDelimitedRow(line: string): string[] | null {
  if (!line.includes("\t")) return null;
  const cells = line.split(/\t+/).map((cell) => cell.trim());
  return cells.length >= 2 ? cells : null;
}

function renderInlineMarkdown(text: string): ReactNode[] {
  return text
    .split(/(\*\*.+?\*\*|__.+?__|`[^`]+`|\*[^*\n]+\*|_[^_\n]+_)/g)
    .filter(Boolean)
    .map((part, index) => {
      if (
        (part.startsWith("**") && part.endsWith("**")) ||
        (part.startsWith("__") && part.endsWith("__"))
      ) {
        return <strong key={index}>{part.slice(2, -2)}</strong>;
      }
      if (
        (part.startsWith("*") && part.endsWith("*")) ||
        (part.startsWith("_") && part.endsWith("_"))
      ) {
        return <em key={index}>{part.slice(1, -1)}</em>;
      }
      if (part.startsWith("`") && part.endsWith("`")) {
        return <code key={index}>{part.slice(1, -1)}</code>;
      }
      return <span key={index}>{part}</span>;
    });
}

function FormattedContent({
  content,
  className,
}: {
  content: unknown;
  className?: string;
}) {
  const lines = String(content ?? "")
    .replace(/\r\n?/g, "\n")
    .split("\n");
  const blocks: ReactNode[] = [];
  for (let index = 0; index < lines.length; index += 1) {
    const trimmed = lines[index].trim();
    if (!trimmed) continue;

    const firstTabRow = splitTabDelimitedRow(lines[index]);
    if (firstTabRow) {
      const tabRows = [firstTabRow];
      let nextIndex = index + 1;
      while (nextIndex < lines.length) {
        const row = splitTabDelimitedRow(lines[nextIndex]);
        if (!row) break;
        tabRows.push(row);
        nextIndex += 1;
      }
      if (
        tabRows.length >= 2 &&
        tabRows.every((row) => row.length === firstTabRow.length)
      ) {
        blocks.push(
          <div className="formatted-table-wrap" key={`tab-table-${index}`}>
            <table className="formatted-table">
              <thead>
                <tr>
                  {firstTabRow.map((cell, cellIndex) => (
                    <th key={cellIndex}>{renderInlineMarkdown(cell)}</th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {tabRows.slice(1).map((row, rowIndex) => (
                  <tr key={rowIndex}>
                    {row.map((cell, cellIndex) => (
                      <td key={cellIndex}>{renderInlineMarkdown(cell)}</td>
                    ))}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>,
        );
        index = nextIndex - 1;
        continue;
      }
    }

    const codeFence = trimmed.match(/^```(\w*)\s*$/);
    if (codeFence) {
      const codeLines: string[] = [];
      index += 1;
      while (index < lines.length && !/^```\s*$/.test(lines[index].trim())) {
        codeLines.push(lines[index]);
        index += 1;
      }
      blocks.push(
        <pre className="formatted-code" key={`code-${index}`}>
          <code>{codeLines.join("\n")}</code>
        </pre>,
      );
      continue;
    }

    const heading = trimmed.match(/^(#{1,6})\s+(.+)$/);
    if (heading) {
      const HeadingTag = heading[1].length <= 2 ? "h2" : "h3";
      blocks.push(
        <HeadingTag className="formatted-heading" key={`heading-${index}`}>
          {renderInlineMarkdown(heading[2])}
        </HeadingTag>,
      );
      continue;
    }

    const boldHeading = trimmed.match(/^\*\*(.+)\*\*$/);
    if (boldHeading) {
      blocks.push(
        <h3 className="formatted-heading" key={`heading-${index}`}>
          {renderInlineMarkdown(boldHeading[1])}
        </h3>,
      );
      continue;
    }

    const listItem = trimmed.match(/^(?:[-*+]|\d+[.)])\s+(.+)$/);
    if (listItem) {
      const ordered = /^\d+[.)]/.test(trimmed);
      const items: string[] = [];
      while (index < lines.length) {
        const match = lines[index]
          .trim()
          .match(/^(?:([-*+])|(\d+[.)]))\s+(.+)$/);
        if (!match || Boolean(match[2]) !== ordered) break;
        items.push(match[3]);
        index += 1;
      }
      index -= 1;
      const ListTag = ordered ? "ol" : "ul";
      blocks.push(
        <ListTag className="formatted-list" key={`list-${index}`}>
          {items.map((item, itemIndex) => (
            <li key={itemIndex}>{renderInlineMarkdown(item)}</li>
          ))}
        </ListTag>,
      );
      continue;
    }

    if (trimmed.startsWith(">")) {
      const quoteLines: string[] = [];
      while (index < lines.length && lines[index].trim().startsWith(">")) {
        quoteLines.push(lines[index].trim().replace(/^>\s?/, ""));
        index += 1;
      }
      index -= 1;
      blocks.push(
        <blockquote className="formatted-quote" key={`quote-${index}`}>
          {quoteLines.map((line, lineIndex) => (
            <p key={lineIndex}>{renderInlineMarkdown(line)}</p>
          ))}
        </blockquote>,
      );
      continue;
    }

    const header = splitMarkdownTableRow(lines[index]);
    const separator =
      index + 1 < lines.length ? splitMarkdownTableRow(lines[index + 1]) : null;
    if (
      header &&
      separator &&
      separator.length === header.length &&
      separator.every((cell) => /^:?-{3,}:?$/.test(cell))
    ) {
      const rows: string[][] = [];
      index += 2;
      while (index < lines.length) {
        const row = splitMarkdownTableRow(lines[index]);
        if (!row) break;
        rows.push(
          Array.from(
            { length: header.length },
            (_, cellIndex) => row[cellIndex] ?? "",
          ),
        );
        index += 1;
      }
      index -= 1;
      blocks.push(
        <div className="formatted-table-wrap" key={`table-${index}`}>
          <table className="formatted-table">
            <thead>
              <tr>
                {header.map((cell, cellIndex) => (
                  <th key={cellIndex}>{renderInlineMarkdown(cell)}</th>
                ))}
              </tr>
            </thead>
            <tbody>
              {rows.map((row, rowIndex) => (
                <tr key={rowIndex}>
                  {row.map((cell, cellIndex) => (
                    <td key={cellIndex}>{renderInlineMarkdown(cell)}</td>
                  ))}
                </tr>
              ))}
            </tbody>
          </table>
        </div>,
      );
    } else {
      const paragraphLines = [lines[index]];
      while (index + 1 < lines.length && lines[index + 1].trim()) {
        const nextLine = lines[index + 1].trim();
        if (
          /^```/.test(nextLine) ||
          /^#{1,6}\s/.test(nextLine) ||
          /^\*\*.+\*\*$/.test(nextLine) ||
          /^(?:[-*+]|\d+[.)])\s+/.test(nextLine) ||
          nextLine.startsWith(">")
        ) {
          break;
        }
        const nextTabRow = splitTabDelimitedRow(lines[index + 1]);
        const followingTabRow =
          index + 2 < lines.length
            ? splitTabDelimitedRow(lines[index + 2])
            : null;
        if (
          nextTabRow &&
          followingTabRow &&
          nextTabRow.length === followingTabRow.length
        ) {
          break;
        }
        const nextHeader = splitMarkdownTableRow(nextLine);
        const nextSeparator =
          index + 2 < lines.length
            ? splitMarkdownTableRow(lines[index + 2])
            : null;
        if (
          nextHeader &&
          nextSeparator &&
          nextSeparator.length === nextHeader.length &&
          nextSeparator.every((cell) => /^:?-{3,}:?$/.test(cell))
        ) {
          break;
        }
        paragraphLines.push(lines[index + 1]);
        index += 1;
      }
      blocks.push(
        <p key={`line-${index}`}>
          {paragraphLines.map((line, lineIndex) => (
            <span key={lineIndex}>
              {lineIndex > 0 && <br />}
              {renderInlineMarkdown(line)}
            </span>
          ))}
        </p>,
      );
    }
  }
  return <div className={className}>{blocks}</div>;
}

function firstReadableText(...values: unknown[]): string {
  for (const value of values) {
    if (typeof value === "string" && value.trim()) {
      const trimmed = value.trim();
      if (trimmed.startsWith("{") || trimmed.startsWith("[")) {
        try {
          const parsed: unknown = JSON.parse(trimmed);
          if (parsed && typeof parsed === "object") {
            const text = firstReadableText(
              (parsed as Data).answer,
              (parsed as Data).response,
              (parsed as Data).message,
              (parsed as Data).content,
              (parsed as Data).text,
            );
            if (text) return text;
            return "";
          }
        } catch {
          return trimmed;
        }
      }
      return trimmed;
    }
    if (value && typeof value === "object" && !Array.isArray(value)) {
      const nested = value as Data;
      const text = firstReadableText(
        nested.answer,
        nested.response,
        nested.message,
        nested.content,
        nested.text,
      );
      if (text) return text;
    }
  }
  return "";
}

type PageImageSource = {
  id: string;
  originalName: string;
  mimeType: string;
  pageNumbers?: number[];
  displayPageNumber?: number;
};

function PageImageGallery({
  files,
  endpoint,
  emptyMessage,
}: {
  files: PageImageSource[];
  endpoint: string;
  emptyMessage?: string;
}) {
  const [pages, setPages] = useState<
    Array<{ name: string; pageNumber: number; url: string }>
  >([]);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState("");
  const fileKey = JSON.stringify(files);

  useEffect(() => {
    let cancelled = false;
    const urls: string[] = [];
    async function loadPages() {
      setPages([]);
      setError("");
      const sources = JSON.parse(fileKey) as PageImageSource[];
      const supported = sources.filter(
        (file) =>
          file.id &&
          file.pageNumbers?.length &&
          (file.mimeType === "application/pdf" ||
            file.mimeType.startsWith("image/")),
      );
      if (!supported.length) {
        setLoading(false);
        return;
      }
      setLoading(true);
      try {
        const output: Array<{
          name: string;
          pageNumber: number;
          url: string;
        }> = [];
        for (const file of supported) {
          const isImage = file.mimeType.startsWith("image/");
          const pageCount = isImage
            ? 1
            : await getTestPageCount(
                `${API_BASE}${endpoint}/${file.id}/pages`,
              );
          if (cancelled) return;
          for (const pageNumber of new Set(file.pageNumbers ?? [])) {
            if (
              !Number.isInteger(pageNumber) ||
              pageNumber < 1 ||
              pageNumber > pageCount
            ) {
              continue;
            }
            if (cancelled) return;
            const blob = await getTestPageBlob(
              isImage
                ? `${API_BASE}${endpoint}/${file.id}`
                : `${API_BASE}${endpoint}/${file.id}/pages/${pageNumber}`,
            );
            if (cancelled) return;
            const url = URL.createObjectURL(blob);
            urls.push(url);
            output.push({
              name: file.originalName,
              pageNumber: file.displayPageNumber ?? pageNumber,
              url,
            });
          }
        }
        if (!cancelled) setPages(output);
      } catch (ex) {
        if (!cancelled) {
          setError(
            ex instanceof Error ? ex.message : "Could not load original pages.",
          );
        }
      } finally {
        if (!cancelled) setLoading(false);
      }
    }
    void loadPages();
    return () => {
      cancelled = true;
      urls.forEach((url) => URL.revokeObjectURL(url));
    };
  }, [endpoint, fileKey]);

  if (loading)
    return <p className="subtle-padding">Loading original PDF pages…</p>;
  if (error) return <p className="alert error">{error}</p>;
  if (!pages.length) {
    return emptyMessage ? (
      <p className="subtle-padding">{emptyMessage}</p>
    ) : null;
  }
  return (
    <div className="original-page-gallery">
      {pages.map((page, index) => (
        <figure key={`${page.name}-${page.pageNumber}-${index}`}>
          <figcaption>
            {page.name} · Page {page.pageNumber}
          </figcaption>
          <img src={page.url} alt={`${page.name}, page ${page.pageNumber}`} />
        </figure>
      ))}
    </div>
  );
}
function LazyDetails({
  className,
  summary,
  children,
}: {
  className?: string;
  summary: ReactNode;
  children: ReactNode;
}) {
  const [hasOpened, setHasOpened] = useState(false);
  return (
    <details
      className={className}
      onToggle={(event) => {
        if (event.currentTarget.open) setHasOpened(true);
      }}
    >
      <summary>{summary}</summary>
      {hasOpened && children}
    </details>
  );
}

function sourcePageImageFiles(
  questionNumber: number,
  questions: Data[],
  sourceFiles: Data[],
  locationProperty: "questionSourceLocations" | "solutionSourceLocations",
): PageImageSource[] {
  const question = questions.find(
    (item) => Number(item.questionNumber) === questionNumber,
  );
  const locations = asArray<Data>(question?.[locationProperty]);
  const linkedFiles = locations.flatMap((location) => {
    const file = sourceFiles[Number(location.sourceIndex)];
    const pageNumbers = asArray<number>(location.pageNumbers);
    return file && pageNumbers.length
      ? [
          {
            id: getId(file),
            originalName: String(file.originalName ?? "Solution source"),
            mimeType: String(file.mimeType ?? ""),
            pageNumbers,
          },
        ]
      : [];
  });
  return linkedFiles;
}

function addPdfFormattedContent(
  pdf: jsPDF,
  content: string,
  options: {
    x: number;
    width: number;
    y: number;
    bottom: number;
    addPage: () => void;
    fontSize?: number;
  },
): number {
  const { x, width, bottom, addPage } = options;
  let y = options.y;
  const fontSize = options.fontSize ?? 10;
  const lines = questionTextForPdf(content).replace(/\r\n?/g, "\n").split("\n");
  const plainText = (text: string) =>
    text.replace(/\*\*([^*]+)\*\*/g, "$1").replace(/[*`]/g, "");
  const drawRichLine = (
    text: string,
    lineX: number,
    lineY: number,
    maxWidth: number,
    lineFontSize: number,
  ) => {
    const segments = text
      .split(/(\*\*[^*]+\*\*|\*[^*]+\*|`[^`]+`)/g)
      .filter(Boolean)
      .flatMap((segment) => {
        const bold = segment.startsWith("**") && segment.endsWith("**");
        const italic =
          !bold && segment.startsWith("*") && segment.endsWith("*");
        const code = segment.startsWith("`") && segment.endsWith("`");
        const value =
          bold || italic || code
            ? segment.slice(bold ? 2 : 1, bold ? -2 : -1)
            : segment;
        return value
          .split(/(\s+)/)
          .filter(Boolean)
          .map((part) => ({
            text: part,
            style: code
              ? "normal"
              : bold
                ? "bold"
                : italic
                  ? "italic"
                  : "normal",
          }));
      });
    const linesToDraw: (typeof segments)[] = [[]];
    let currentWidth = 0;
    for (const segment of segments) {
      pdf.setFont("helvetica", segment.style);
      pdf.setFontSize(lineFontSize);
      const segmentWidth = pdf.getTextWidth(segment.text);
      if (
        segment.text.trim() &&
        currentWidth + segmentWidth > maxWidth &&
        linesToDraw[linesToDraw.length - 1].length
      ) {
        linesToDraw.push([]);
        currentWidth = 0;
      }
      linesToDraw[linesToDraw.length - 1].push(segment);
      currentWidth += segmentWidth;
    }
    let drawY = lineY;
    for (const lineSegments of linesToDraw) {
      if (drawY + 5 > bottom) {
        addPage();
        drawY = 20;
      }
      let drawX = lineX;
      for (const segment of lineSegments) {
        pdf.setFont("helvetica", segment.style);
        pdf.setFontSize(lineFontSize);
        pdf.text(segment.text, drawX, drawY);
        drawX += pdf.getTextWidth(segment.text);
      }
      drawY += 5;
    }
    return drawY;
  };

  for (let index = 0; index < lines.length; index += 1) {
    const header = splitMarkdownTableRow(lines[index]);
    const separator =
      index + 1 < lines.length ? splitMarkdownTableRow(lines[index + 1]) : null;
    if (
      header &&
      separator &&
      separator.length === header.length &&
      separator.every((cell) => /^:?-{3,}:?$/.test(cell))
    ) {
      const rows: string[][] = [header];
      index += 2;
      while (index < lines.length) {
        const row = splitMarkdownTableRow(lines[index]);
        if (!row) break;
        rows.push(
          Array.from(
            { length: header.length },
            (_, cellIndex) => row[cellIndex] ?? "",
          ),
        );
        index += 1;
      }
      index -= 1;
      const columnWidth = width / header.length;
      const cellFontSize = Math.min(fontSize, 8);
      pdf.setFontSize(cellFontSize);
      rows.forEach((row, rowIndex) => {
        const wrapped = row.map((cell) =>
          pdf.splitTextToSize(plainText(cell), columnWidth - 3),
        );
        const rowHeight = Math.max(
          6,
          ...wrapped.map((cell) => cell.length * 3.8 + 2),
        );
        if (y + rowHeight > bottom) {
          addPage();
          y = 20;
          pdf.setFontSize(cellFontSize);
          if (rowIndex > 0) {
            const headerLines = rows[0].map((cell) =>
              pdf.splitTextToSize(plainText(cell), columnWidth - 3),
            );
            const headerHeight = Math.max(
              6,
              ...headerLines.map((cell) => cell.length * 3.8 + 2),
            );
            pdf.setFont("helvetica", "bold");
            headerLines.forEach((cellLines, cellIndex) => {
              const cellX = x + cellIndex * columnWidth;
              pdf.setFillColor(235, 241, 249);
              pdf.rect(cellX, y, columnWidth, headerHeight, "F");
              pdf.setDrawColor(190, 200, 214);
              pdf.rect(cellX, y, columnWidth, headerHeight);
              pdf.text(cellLines, cellX + 1.5, y + 3.5, {
                lineHeightFactor: 1.1,
              });
            });
            y += headerHeight;
          }
        }
        pdf.setFont(
          rowIndex === 0 ? "helvetica" : "helvetica",
          rowIndex === 0 ? "bold" : "normal",
        );
        wrapped.forEach((cellLines, cellIndex) => {
          const cellX = x + cellIndex * columnWidth;
          if (rowIndex === 0) {
            pdf.setFillColor(235, 241, 249);
            pdf.rect(cellX, y, columnWidth, rowHeight, "F");
          }
          pdf.setDrawColor(190, 200, 214);
          pdf.rect(cellX, y, columnWidth, rowHeight);
          pdf.setTextColor(45, 57, 74);
          pdf.text(cellLines, cellX + 1.5, y + 3.5, {
            lineHeightFactor: 1.1,
          });
        });
        y += rowHeight;
      });
      y += 3;
      pdf.setFont("helvetica", "normal");
      pdf.setFontSize(fontSize);
      continue;
    }

    const rawLine = lines[index].trim();
    if (/^[-*_]{3,}$/.test(rawLine)) {
      if (y + 3 > bottom) {
        addPage();
        y = 20;
      }
      pdf.setDrawColor(205, 215, 228);
      pdf.line(x, y, x + width, y);
      y += 5;
      continue;
    }
    const headingMatch = rawLine.match(/^(#{1,3})\s+(.+)$/);
    const plainHeading =
      !headingMatch &&
      rawLine.length <= 90 &&
      (/^(?:notes to the financial statements|statement of (?:financial position|comprehensive income|profit or loss)|recognized solution|recognized answer|working \d+|[ivxlcdm]+\.)/i.test(
        rawLine,
      ) ||
        (rawLine === rawLine.toUpperCase() && /[A-Z]/.test(rawLine)));
    if (headingMatch || plainHeading) {
      const headingText = headingMatch ? headingMatch[2] : rawLine;
      const headingSize =
        headingMatch && headingMatch[1].length === 1
          ? fontSize + 2
          : fontSize + 1;
      if (y + headingSize > bottom) {
        addPage();
        y = 20;
      }
      y += 1;
      pdf.setTextColor(33, 55, 84);
      y = drawRichLine(headingText, x, y, width, headingSize);
      pdf.setTextColor(45, 57, 74);
      y += 1;
      continue;
    }
    const listMatch = rawLine.match(/^(\s*)([-*+]|\d+[.)])\s+(.+)$/);
    const text = plainText(listMatch ? listMatch[3] : rawLine).trim();
    if (!text) {
      y += 2;
      continue;
    }
    const indent = listMatch ? Math.min(6, (listMatch[1].length / 2) * 2) : 0;
    if (listMatch && y + 5 > bottom) {
      addPage();
      y = 20;
    }
    if (listMatch) {
      pdf.setFont("helvetica", "bold");
      pdf.setFontSize(fontSize);
      pdf.text(`${listMatch[2]}`, x + indent, y);
    }
    y = drawRichLine(
      listMatch ? listMatch[3] : rawLine,
      x + indent + (listMatch ? 5 : 0),
      y,
      width - indent - (listMatch ? 5 : 0),
      fontSize,
    );
  }
  return y;
}
function downloadText(
  filename: string,
  contents: string,
  mimeType = "text/plain;charset=utf-8",
) {
  const url = URL.createObjectURL(new Blob([contents], { type: mimeType }));
  const link = document.createElement("a");
  link.href = url;
  link.download = filename;
  link.click();
  URL.revokeObjectURL(url);
}
type MindMapLayoutNode = {
  label: string;
  depth: number;
  top: number;
  height: number;
  centerY: number;
  children: MindMapLayoutNode[];
};
function escapeSvgText(value: string) {
  return value.replace(/[&<>"']/g, (character) => {
    const entities: Record<string, string> = {
      "&": "&amp;",
      "<": "&lt;",
      ">": "&gt;",
      '"': "&quot;",
      "'": "&apos;",
    };
    return entities[character];
  });
}
function wrapMindMapLabel(value: string, maxChars = 27) {
  const words = value.trim().split(/\s+/).filter(Boolean);
  const lines: string[] = [];
  let line = "";
  words.forEach((word) => {
    if (line && `${line} ${word}`.length > maxChars) {
      lines.push(line);
      line = word;
    } else {
      line = line ? `${line} ${word}` : word;
    }
  });
  if (line) lines.push(line);
  return lines.length ? lines : ["Topic"];
}
function exportMindMapSvg(studyAid: Data) {
  const rootLabel = String(studyAid.title ?? "Mind map");
  const rootData = {
    label: rootLabel,
    children: asArray<Data>(studyAid.branches),
  };
  const measure = (node: Data, depth: number): MindMapLayoutNode => {
    const label = String(node.label ?? (depth === 0 ? rootLabel : "Topic"));
    const children = asArray<Data>(node.children).map((child) =>
      measure(child, depth + 1),
    );
    const ownHeight = Math.max(62, wrapMindMapLabel(label).length * 21 + 28);
    const childrenHeight =
      children.reduce((total, child) => total + child.height, 0) +
      Math.max(0, children.length - 1) * 28;
    return {
      label,
      depth,
      top: 0,
      height: Math.max(ownHeight, childrenHeight),
      centerY: 0,
      children,
    };
  };
  const root = measure(rootData, 0);
  const layout = (node: MindMapLayoutNode, top: number) => {
    node.top = top;
    if (!node.children.length) {
      node.centerY = top + node.height / 2;
      return;
    }
    const childrenHeight =
      node.children.reduce((total, child) => total + child.height, 0) +
      (node.children.length - 1) * 28;
    let childTop = top + (node.height - childrenHeight) / 2;
    node.children.forEach((child) => {
      layout(child, childTop);
      childTop += child.height + 28;
    });
    node.centerY =
      (node.children[0].centerY +
        node.children[node.children.length - 1].centerY) /
      2;
  };
  layout(root, 0);
  const nodes: MindMapLayoutNode[] = [];
  const collect = (node: MindMapLayoutNode) => {
    nodes.push(node);
    node.children.forEach(collect);
  };
  collect(root);
  const maxDepth = Math.max(0, ...nodes.map((node) => node.depth));
  const width = 140 + (maxDepth + 1) * 280;
  const height = Math.max(220, root.height + 64);
  const nodeWidth = 230;
  const connectors = nodes.flatMap((node) =>
    node.children.map((child) => {
      const startX = 60 + node.depth * 280 + nodeWidth;
      const endX = 60 + child.depth * 280;
      const bendX = (startX + endX) / 2;
      return `<path d="M ${startX} ${node.centerY} C ${bendX} ${node.centerY}, ${bendX} ${child.centerY}, ${endX} ${child.centerY}" fill="none" stroke="#a9bfdc" stroke-width="2.5"/>`;
    }),
  );
  const nodeMarkup = nodes.map((node) => {
    const x = 60 + node.depth * 280;
    const lines = wrapMindMapLabel(node.label);
    const rectHeight = Math.max(62, lines.length * 21 + 28);
    const y = node.centerY - rectHeight / 2;
    const text = lines
      .map(
        (line, index) =>
          `<tspan x="${x + nodeWidth / 2}" dy="${index === 0 ? 0 : 21}">${escapeSvgText(line)}</tspan>`,
      )
      .join("");
    return `<g><rect x="${x}" y="${y}" width="${nodeWidth}" height="${rectHeight}" rx="14" fill="${node.depth === 0 ? "#234f91" : node.depth === 1 ? "#edf4ff" : "#ffffff"}" stroke="${node.depth === 0 ? "#234f91" : "#b9cce8"}" stroke-width="2"/><text x="${x + nodeWidth / 2}" y="${node.centerY - ((lines.length - 1) * 21) / 2}" text-anchor="middle" dominant-baseline="middle" font-family="Arial, sans-serif" font-size="${node.depth === 0 ? 17 : 15}" font-weight="${node.depth < 2 ? 700 : 500}" fill="${node.depth === 0 ? "#ffffff" : "#26364b"}">${text}</text></g>`;
  });
  const svg = `<svg xmlns="http://www.w3.org/2000/svg" width="${width}" height="${height}" viewBox="0 0 ${width} ${height}" role="img" aria-labelledby="map-title map-description"><title id="map-title">${escapeSvgText(rootLabel)}</title><desc id="map-description">A hierarchical mind map with connected topics and subtopics.</desc><rect width="100%" height="100%" fill="#f8fbff"/>${connectors.join("")}${nodeMarkup.join("")}</svg>`;
  const filename = rootLabel
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, "-")
    .replace(/^-|-$/g, "");
  downloadText(
    `nexus-${filename || "mind-map"}.svg`,
    svg,
    "image/svg+xml;charset=utf-8",
  );
}
function roleIsAdmin(user: Person | null) {
  return String(user?.role ?? "").toLowerCase() === "admin";
}

type ResourceSubscription = {
  path: string;
  invalidate: () => void;
};

const resourceCache = new Map<string, unknown>();
const resourceGenerations = new Map<string, number>();
const pendingResourceRequests = new Map<
  string,
  { generation: number; authGeneration: number; promise: Promise<unknown> }
>();
const resourceSubscriptions = new Set<ResourceSubscription>();
const resourceFetchedAt = new Map<string, number>();
const PERSISTED_TEST_CACHE_PREFIX = "nexus.test-cache.v1:";
const PERSISTED_TEST_CACHE_MAX_BYTES = 3 * 1024 * 1024;
// The API has no push channel, so data that changes on the server (attempt status, grading
// results, leaderboard, support replies) is cached only briefly and then read again.
const LIVE_RESOURCE_MAX_AGE_MS = 60 * 1000;
let resourceCacheToken = getToken();
let resourceAuthGeneration = 0;
let resourceCacheUserId: string | null = null;

function isPersistentTestResource(path: string) {
  return (
    path === "/tests/me/attempts" ||
    /^\/tests\/[^/]+\/me$/.test(path)
  );
}

function resourceMaxAgeMs(path: string) {
  return isPersistentTestResource(path) ||
    /^\/tests\/[^/]+\/me(?:\/|$)/.test(path) ||
    path === "/leaderboard" ||
    path === "/student-queries"
    ? LIVE_RESOURCE_MAX_AGE_MS
    : Number.POSITIVE_INFINITY;
}

function persistentTestCacheKey(path: string) {
  if (!resourceCacheUserId) return null;
  return `${PERSISTED_TEST_CACHE_PREFIX}${encodeURIComponent(resourceCacheUserId)}:${encodeURIComponent(path)}`;
}

function persistentTestCachePrefix() {
  return resourceCacheUserId
    ? `${PERSISTED_TEST_CACHE_PREFIX}${encodeURIComponent(resourceCacheUserId)}:`
    : null;
}

function readPersistentTestResource<T>(path: string): T | undefined {
  if (!isPersistentTestResource(path)) return undefined;
  const key = persistentTestCacheKey(path);
  if (!key) return undefined;
  try {
    const raw = localStorage.getItem(key);
    if (!raw) return undefined;
    const entry = JSON.parse(raw) as {
      version?: number;
      cachedAt?: number;
      path?: string;
      data?: T;
    };
    if (
      entry.version !== 1 ||
      entry.path !== path ||
      typeof entry.cachedAt !== "number" ||
      Date.now() - entry.cachedAt > resourceMaxAgeMs(path) ||
      !("data" in entry)
    ) {
      localStorage.removeItem(key);
      return undefined;
    }
    resourceFetchedAt.set(path, entry.cachedAt);
    return entry.data;
  } catch {
    return undefined;
  }
}

function writePersistentTestResource(path: string, data: unknown) {
  if (!isPersistentTestResource(path)) return;
  const key = persistentTestCacheKey(path);
  const prefix = persistentTestCachePrefix();
  if (!key || !prefix) return;
  try {
    const serialized = JSON.stringify({
      version: 1,
      cachedAt: Date.now(),
      path,
      data,
    });
    if (serialized.length > PERSISTED_TEST_CACHE_MAX_BYTES) return;

    const entries: Array<{ key: string; cachedAt: number; size: number }> = [];
    let totalBytes = 0;
    for (let index = 0; index < localStorage.length; index += 1) {
      const existingKey = localStorage.key(index);
      if (!existingKey?.startsWith(prefix) || existingKey === key) continue;
      const raw = localStorage.getItem(existingKey);
      if (!raw) continue;
      totalBytes += raw.length;
      try {
        const entry = JSON.parse(raw) as { cachedAt?: number };
        entries.push({
          key: existingKey,
          cachedAt: Number(entry.cachedAt) || 0,
          size: raw.length,
        });
      } catch {
        entries.push({ key: existingKey, cachedAt: 0, size: raw.length });
      }
    }
    entries.sort((left, right) => left.cachedAt - right.cachedAt);
    while (
      totalBytes + serialized.length > PERSISTED_TEST_CACHE_MAX_BYTES &&
      entries.length
    ) {
      const oldest = entries.shift()!;
      localStorage.removeItem(oldest.key);
      totalBytes -= oldest.size;
    }
    localStorage.setItem(key, serialized);
  } catch {
    // The in-memory cache remains available if browser storage is unavailable or full.
  }
}

function removePersistentTestResources(
  shouldRemove: (path: string) => boolean = () => true,
) {
  const prefix = persistentTestCachePrefix();
  if (!prefix) return;
  try {
    const keys: string[] = [];
    for (let index = 0; index < localStorage.length; index += 1) {
      const key = localStorage.key(index);
      if (!key?.startsWith(prefix)) continue;
      const path = decodeURIComponent(key.slice(prefix.length));
      if (shouldRemove(path)) keys.push(key);
    }
    keys.forEach((key) => localStorage.removeItem(key));
  } catch {
    // Persistent storage is optional; the authenticated server remains authoritative.
  }
}

function getCachedResource<T>(path: string): T | undefined {
  ensureResourceCacheScope();
  if (resourceCache.has(path)) {
    const fetchedAt = resourceFetchedAt.get(path);
    if (
      fetchedAt === undefined ||
      Date.now() - fetchedAt <= resourceMaxAgeMs(path)
    ) {
      return resourceCache.get(path) as T;
    }
    resourceCache.delete(path);
    resourceFetchedAt.delete(path);
  }
  const persisted = readPersistentTestResource<T>(path);
  if (persisted !== undefined) resourceCache.set(path, persisted);
  return persisted;
}

function ensureResourceCacheScope() {
  const token = getToken();
  if (token !== resourceCacheToken) {
    resourceCacheToken = token;
    resourceAuthGeneration += 1;
    resourceCache.clear();
    resourceGenerations.clear();
    pendingResourceRequests.clear();
    resourceFetchedAt.clear();
  }
}

function setResourceCacheUser(userId: string | null) {
  if (userId === resourceCacheUserId) return;
  resourceCacheUserId = userId;
  resourceCache.clear();
  resourceGenerations.clear();
  pendingResourceRequests.clear();
  resourceFetchedAt.clear();
}

function clearResourceCache() {
  clearTestAssetCache();
  removePersistentTestResources();
  resourceCacheToken = getToken();
  resourceAuthGeneration += 1;
  resourceCache.clear();
  resourceGenerations.clear();
  pendingResourceRequests.clear();
  resourceFetchedAt.clear();
}

function invalidateResourcePaths(paths: Iterable<string>) {
  let invalidated = false;
  for (const path of paths) {
    resourceCache.delete(path);
    resourceFetchedAt.delete(path);
    removePersistentTestResources((cachedPath) => cachedPath === path);
    resourceGenerations.set(path, (resourceGenerations.get(path) ?? 0) + 1);
    pendingResourceRequests.delete(path);
    invalidated = true;
    for (const subscription of resourceSubscriptions) {
      if (subscription.path === path) subscription.invalidate();
    }
  }
  return invalidated;
}

function loadResource<T>(path: string, force = false): Promise<T> {
  ensureResourceCacheScope();
  const cached = getCachedResource<T>(path);
  if (!force && cached !== undefined) {
    return Promise.resolve(cached);
  }

  const generation = resourceGenerations.get(path) ?? 0;
  const pending = pendingResourceRequests.get(path);
  if (
    pending?.generation === generation &&
    pending.authGeneration === resourceAuthGeneration
  ) {
    return pending.promise as Promise<T>;
  }

  const authGeneration = resourceAuthGeneration;
  const promise = api<T>(path).then((value) => {
    ensureResourceCacheScope();
    if (
      authGeneration === resourceAuthGeneration &&
      generation === (resourceGenerations.get(path) ?? 0)
    ) {
      resourceCache.set(path, value);
      resourceFetchedAt.set(path, Date.now());
      writePersistentTestResource(path, value);
    }
    return value;
  });
  pendingResourceRequests.set(path, {
    generation,
    authGeneration,
    promise,
  });
  void promise.then(
    () => {
      if (pendingResourceRequests.get(path)?.promise === promise) {
        pendingResourceRequests.delete(path);
      }
    },
    () => {
      if (pendingResourceRequests.get(path)?.promise === promise) {
        pendingResourceRequests.delete(path);
      }
    },
  );
  return promise;
}

function resourceMatchesUpdate(path: string, resource: string) {
  switch (resource) {
    case "sync":
      return false;
    case "dashboard":
      return path === "/tests/me/attempts";
    case "my-tests":
      return (
        path === "/tests/me/attempts" ||
        /^\/tests\/[^/]+\/me(?:\/|$)/.test(path)
      );
    case "top-performers":
      return path === "/leaderboard";
    case "admin-contact":
      return path === "/student-queries";
    default:
      return false;
  }
}

function invalidateResourceCache(resources: string | string[]) {
  ensureResourceCacheScope();
  const updates = new Set(Array.isArray(resources) ? resources : [resources]);
  const persistedPaths: string[] = [];
  const prefix = persistentTestCachePrefix();
  if (prefix) {
    try {
      for (let index = 0; index < localStorage.length; index += 1) {
        const key = localStorage.key(index);
        if (key?.startsWith(prefix)) {
          persistedPaths.push(decodeURIComponent(key.slice(prefix.length)));
        }
      }
    } catch {
      // Ignore unavailable browser storage; active in-memory resources still synchronize.
    }
  }
  const paths = new Set([
    ...resourceCache.keys(),
    ...pendingResourceRequests.keys(),
    ...Array.from(resourceSubscriptions, (subscription) => subscription.path),
    ...persistedPaths,
  ]);
  const invalidatedPaths: string[] = [];
  for (const path of paths) {
    if (
      !Array.from(updates).some((resource) =>
        resourceMatchesUpdate(path, resource),
      )
    ) {
      continue;
    }
    invalidatedPaths.push(path);
  }
  return invalidateResourcePaths(invalidatedPaths);
}

function App() {
  const pathname = location.pathname.replace(/\/+$/, "") || "/";
  const adminArea = pathname === "/admin" || pathname.startsWith("/admin/");
  const adminLoginPath = pathname === "/admin/login";
  const [user, setUser] = useState<Person | null>(null),
    [boot, setBoot] = useState(true),
    [authError, setAuthError] = useState(""),
    [page, setPage] = useState<Page>(
      (location.hash.slice(1) as Page) || "dashboard",
    ),
    [mobile, setMobile] = useState(false),
    [showNavOrder, setShowNavOrder] = useState(false),
    [navOrder, setNavOrder] = useState<Page[]>([]),
    [draggingPage, setDraggingPage] = useState<Page | null>(null),
    [toast, setToast] = useState("");
  const setAuthenticatedUser = (nextUser: Person | null) => {
    setTestAssetCacheUser(nextUser?.id ?? null);
    setResourceCacheUser(nextUser?.id ?? null);
    setUser(nextUser);
  };
  useEffect(() => {
    const token = getToken();
    if (!token) {
      setBoot(false);
      return;
    }
    api<Person>("/auth/me")
      .then(setAuthenticatedUser)
      .catch(() => {
        setToken(null);
        clearResourceCache();
      })
      .finally(() => setBoot(false));
  }, []);
  useEffect(() => {
    const onHash = () =>
      setPage((location.hash.slice(1) as Page) || "dashboard");
    window.addEventListener("hashchange", onHash);
    return () => window.removeEventListener("hashchange", onHash);
  }, []);
  useEffect(() => {
    if (user && adminLoginPath && roleIsAdmin(user)) {
      window.history.replaceState(null, "", "/admin");
    }
  }, [user, adminLoginPath]);
  useEffect(() => {
    setNavOrder([]);
    if (!user || !roleIsAdmin(user)) return;
    try {
      const saved = localStorage.getItem(`nexus.admin.navOrder.${user.id}`);
      const parsed = saved ? JSON.parse(saved) : [];
      if (Array.isArray(parsed)) setNavOrder(parsed.filter((id): id is Page =>
        typeof id === "string" && adminLinks.some((link) => link.id === id),
      ));
    } catch {
      setNavOrder([]);
    }
  }, [user?.id]);
  useEffect(() => {
    if (adminArea && !boot && !user && !adminLoginPath) {
      window.history.replaceState(null, "", "/admin/login");
    }
  }, [adminArea, boot, user, adminLoginPath]);
  useEffect(() => {
    if (adminLoginPath && user && !roleIsAdmin(user)) {
      setToken(null);
      clearResourceCache();
      setAuthenticatedUser(null);
      setAuthError(
        "This is the administrator sign-in page. Sign in with an administrator account.",
      );
    }
  }, [adminLoginPath, user]);
  const navigate = (next: Page) => {
    location.hash = next;
    setPage(next);
    setMobile(false);
  };
  const notify = (s: string) => {
    setToast(s);
    window.setTimeout(() => setToast(""), 3500);
  };
  async function signOut() {
    try {
      await api("/auth/signout", { method: "POST" });
    } catch {
      /* local credentials are cleared even if server is offline */
    }
    setToken(null);
    clearResourceCache();
    setAuthenticatedUser(null);
    navigate("dashboard");
  }
  function passwordChanged() {
    const wasAdmin = roleIsAdmin(user);
    setToken(null);
    clearResourceCache();
    setAuthenticatedUser(null);
    setAuthError("Password updated. Sign in again with your new password.");
    window.history.replaceState(null, "", wasAdmin ? "/admin/login" : "/");
  }
  if (boot)
    return (
      <div className="boot">
        <div className="brand-mark">
          <GraduationCap />
        </div>
        <span>Connecting to Nexus…</span>
      </div>
    );
  if (!user)
    return (
      <Login
        adminOnly={adminArea}
        onSuccess={setAuthenticatedUser}
        error={authError}
        setError={setAuthError}
      />
    );
  if (adminLoginPath && !roleIsAdmin(user)) {
    return (
      <Login
        adminOnly
        onSuccess={setAuthenticatedUser}
        error="This is the administrator sign-in page. Sign in with an administrator account."
        setError={setAuthError}
      />
    );
  }
  const admin = roleIsAdmin(user),
    canManageSubjects = Boolean(
      admin &&
      (user.adminPermissions?.includes("admin") ||
        user.adminPermissions?.includes("teacher_assistant")),
    ),
    permissions = new Set(user.adminPermissions ?? []),
    links = admin
      ? adminLinks.filter((link) => {
          if (permissions.has("admin")) return true;
          const requiredPermissions: Partial<Record<Page, string[]>> = {
            academic: ["teacher_assistant"],
            team: [],
            "test-admin": ["teacher_assistant", "testing_manager"],
            settings: ["admin"],
            students: ["user_manager"],
            users: ["user_manager"],
            passwords: ["password_manager"],
            "access-requests": ["testing_manager", "teacher_assistant"],
            notebook: ["teacher_assistant"],
            queries: ["student_query"],
            analytics: ["testing_manager"],
          };
          if (link.id === "team") return false;
          if (link.id === "my-password") return true;
          return (
            requiredPermissions[link.id]?.some((permission) =>
              permissions.has(permission),
            ) ?? false
          );
        })
      : studentLinks;
  const savedOrder = navOrder.length
    ? navOrder
    : admin
      ? (() => {
          try {
            const raw = localStorage.getItem(`nexus.admin.navOrder.${user.id}`);
            const parsed = raw ? JSON.parse(raw) : [];
            return Array.isArray(parsed) ? parsed.filter((id): id is Page =>
              typeof id === "string" && adminLinks.some((link) => link.id === id),
            ) : [];
          } catch {
            return [];
          }
        })()
      : [];
  const orderedLinks = admin
    ? [...links].sort((left, right) => {
        const defaultOrder: Page[] = ["analytics", ...adminLinks.filter((link) => link.id !== "analytics").map((link) => link.id)];
        const order = savedOrder.length
          ? [...savedOrder, ...defaultOrder.filter((id) => !savedOrder.includes(id))]
          : defaultOrder;
        return order.indexOf(left.id) - order.indexOf(right.id);
      })
    : links;
  const saveNavOrder = (from: Page, to: Page) => {
    const next = orderedLinks.map((item) => item.id);
    const fromIndex = next.indexOf(from);
    const toIndex = next.indexOf(to);
    if (fromIndex < 0 || toIndex < 0 || fromIndex === toIndex) return;
    next.splice(toIndex, 0, ...next.splice(fromIndex, 1));
    setNavOrder(next);
    localStorage.setItem(`nexus.admin.navOrder.${user.id}`, JSON.stringify(next));
  };
  const allowed = orderedLinks.some((l) => l.id === page) ? page : (orderedLinks[0]?.id ?? "notebook");
  return (
    <div className="layout">
      <aside className={`sidebar ${mobile ? "sidebar-open" : ""}`}>
        <div className="brand">
          <div className="brand-mark">
            <GraduationCap size={21} />
          </div>
          <div>
            <strong>NEXUS</strong>
            <span>LEARNING PORTAL</span>
          </div>
          <button
            className="icon-button mobile-close"
            aria-label="Close navigation"
            onClick={() => setMobile(false)}
          >
            <X size={18} />
          </button>
        </div>
        <div className="nav-caption">
          {admin ? "ADMIN WORKSPACE" : "STUDENT SPACE"}
        </div>
        {admin && (
          <button
            type="button"
            className="nav-customize-button"
            aria-expanded={showNavOrder}
            onClick={() => setShowNavOrder((open) => !open)}
          >
            {showNavOrder ? "Done arranging tabs" : "Customize tab order"}
          </button>
        )}
        <nav aria-label="Main navigation">
          {orderedLinks.map((item) => {
            const Icon = item.icon;
            return (
              <button
                key={item.id}
                draggable={admin}
                onDragStart={() => setDraggingPage(item.id)}
                onDragOver={(event) => {
                  if (admin && draggingPage) event.preventDefault();
                }}
                onDrop={(event) => {
                  event.preventDefault();
                  if (draggingPage) saveNavOrder(draggingPage, item.id);
                  setDraggingPage(null);
                }}
                onDragEnd={() => setDraggingPage(null)}
                className={`nav-item ${allowed === item.id ? "nav-active" : ""}`}
                onClick={() => navigate(item.id)}
              >
                <Icon size={18} />
                <span>{item.title}</span>
              </button>
            );
          })}
          {admin && showNavOrder && (
            <div className="nav-order-panel" aria-label="Reorder navigation tabs">
              <p>Drag tabs or use the arrow buttons to reorder.</p>
              {orderedLinks.map((item, index) => (
                <div
                  className="nav-order-row"
                  key={item.id}
                  draggable
                  onDragStart={() => setDraggingPage(item.id)}
                  onDragOver={(event) => event.preventDefault()}
                  onDrop={(event) => {
                    event.preventDefault();
                    if (draggingPage) saveNavOrder(draggingPage, item.id);
                    setDraggingPage(null);
                  }}
                  onDragEnd={() => setDraggingPage(null)}
                >
                  <span>{item.title}</span>
                  <button type="button" aria-label={`Move ${item.title} up`} disabled={index === 0} onClick={() => saveNavOrder(item.id, orderedLinks[index - 1].id)}>↑</button>
                  <button type="button" aria-label={`Move ${item.title} down`} disabled={index === orderedLinks.length - 1} onClick={() => saveNavOrder(item.id, orderedLinks[index + 1].id)}>↓</button>
                </div>
              ))}
            </div>
          )}
        </nav>
        <div className="sidebar-bottom">
          <div className="secure-note">
            <ShieldCheck size={16} />
            <div>
              <b>Secure workspace</b>
              <span>Access managed by your account</span>
            </div>
          </div>
          <button className="profile" onClick={() => navigate("my-password")}>
            <div className="avatar">
              {(user.fullName || user.email || "N").slice(0, 1).toUpperCase()}
            </div>
            <div className="profile-copy">
              <strong>{user.fullName || user.email || "Nexus user"}</strong>
              <span>{admin ? "Administrator" : "Student"}</span>
            </div>
            <LogOut
              size={17}
              className="logout"
              onClick={(e) => {
                e.stopPropagation();
                void signOut();
              }}
              aria-label="Sign out"
            />
          </button>
        </div>
      </aside>
      {mobile && (
        <button
          className="scrim"
          aria-label="Close menu"
          onClick={() => setMobile(false)}
        />
      )}
      <main className="main">
        <header className="topbar">
          <button
            className="icon-button menu-button"
            aria-label="Open menu"
            onClick={() => setMobile(true)}
          >
            <Menu />
          </button>
          <div className="crumb">
            <span>Workspace</span>
            <ChevronRight size={14} />
            <b>
              {
                [...studentLinks, ...adminLinks].find((x) => x.id === allowed)
                  ?.title
              }
            </b>
          </div>
          <div className="top-actions">
            <span className="role-chip">
              <span className="online-dot" />
              {admin ? "Admin" : "Student"} account
            </span>
            <button
              className="icon-button"
              title="Sign out"
              aria-label="Sign out"
              onClick={() => void signOut()}
            >
              <LogOut size={18} />
            </button>
          </div>
        </header>
        <div className="content">
          {user.mustResetPassword ? (
            <ChangePassword
              required
              onDone={passwordChanged}
            />
          ) : (
            <PageContent
              page={allowed}
              user={user}
              notify={notify}
              canManageSources={canManageSubjects}
              onPasswordChanged={passwordChanged}
            />
          )}
        </div>
      </main>
      {toast && (
        <div role="status" className="toast">
          <Check size={17} />
          {toast}
        </div>
      )}
    </div>
  );
}
function Login({
  adminOnly,
  onSuccess,
  error,
  setError,
}: {
  adminOnly: boolean;
  onSuccess: (u: Person) => void;
  error: string;
  setError: (s: string) => void;
}) {
  const [email, setEmail] = useState(""),
    [password, setPassword] = useState(""),
    [busy, setBusy] = useState(false);
  async function submit(e: FormEvent) {
    e.preventDefault();
    setBusy(true);
    setError("");
    try {
      const result = await api<Data>(
        "/auth/login",
        json("POST", { email, password }),
      );
      if (!result.accessToken || !result.refreshToken || !result.user?.id)
        throw new Error("Login response did not include a complete session.");
      setSessionTokens(
        result.accessToken,
        result.refreshToken,
        result.user.id,
      );
      const me = await api<Person>("/auth/me");
      if (adminOnly && !roleIsAdmin(me)) {
        throw new Error(
          "This sign-in page is for administrators. Use a student account on the student portal.",
        );
      }
      onSuccess(me);
    } catch (ex) {
      setError(ex instanceof Error ? ex.message : "Unable to sign in.");
      setToken(null);
    } finally {
      setBusy(false);
    }
  }
  return (
    <div className="auth-screen">
      <div className="auth-orb orb-one" />
      <div className="auth-orb orb-two" />
      <section className="login-card">
        <div className="login-brand">
          <div className="brand-mark">
            <GraduationCap size={24} />
          </div>
          <div>
            <strong>NEXUS</strong>
            <span>LEARNING PORTAL</span>
          </div>
        </div>
        <div className="eyebrow">
          {adminOnly ? "ADMINISTRATOR ACCESS" : "WELCOME BACK"}
        </div>
        <h1>
          {adminOnly ? "Admin workspace" : "Your next step"}
          <br />
          {adminOnly ? "sign in." : "starts here."}
        </h1>
        <p className="muted">
          {adminOnly
            ? "Sign in with your administrator account to continue."
            : "Sign in to continue to your learning workspace."}
        </p>
        <form onSubmit={submit} className="form-stack">
          <label>
            Email address
            <input
              type="email"
              autoComplete="username"
              required
              value={email}
              onChange={(e) => setEmail(e.target.value)}
              placeholder="you@school.edu"
            />
          </label>
          <label>
            Password
            <input
              type="password"
              autoComplete="current-password"
              required
              value={password}
              onChange={(e) => setPassword(e.target.value)}
              placeholder="Enter your password"
            />
          </label>
          {error && (
            <div className="alert error" role="alert">
              {error}
            </div>
          )}
          <button className="button primary full" disabled={busy}>
            {busy ? "Signing in…" : "Sign in securely"}
            <ArrowRight size={17} />
          </button>
        </form>
      </section>
      <div className="auth-tagline">
        <Sparkles size={18} /> A focused space for learning and progress
      </div>
    </div>
  );
}
function PageContent({
  page,
  user,
  notify,
  canManageSources,
  onPasswordChanged,
}: {
  page: Page;
  user: Person;
  notify: (s: string) => void;
  canManageSources: boolean;
  onPasswordChanged: () => void;
}) {
  switch (page) {
    case "dashboard":
      return <Dashboard user={user} />;
    case "notebook":
      return <Notebook admin={canManageSources} notify={notify} />;
    case "tests":
      return <StudentTests />;
    case "performers":
      return <Performers />;
    case "contact":
      return <Queries admin={false} />;
    case "academic":
      return <Academic canManageSources={canManageSources} />;
    case "team":
      return <Team />;
    case "test-admin":
      return <TestAdmin user={user} notify={notify} />;
    case "settings":
      return <TestAdmin user={user} notify={notify} settingsOnly />;
    case "access-requests":
      return <AccessRequests notify={notify} />;
    case "students":
      return <Students />;
    case "users":
      return <UsersAdmin />;
    case "passwords":
      return <ManagedPasswords />;
    case "my-password":
      return <ChangePassword onDone={onPasswordChanged} />;
    case "queries":
      return <Queries admin />;
    case "analytics":
      return <Analytics />;
    default:
      return <Notebook admin={canManageSources} notify={notify} />;
  }
}
function Heading({
  eyebrow,
  title,
  description,
  action,
}: {
  eyebrow?: string;
  title: string;
  description?: string;
  action?: ReactNode;
}) {
  return (
    <div className="page-heading">
      <div>
        {eyebrow && <div className="eyebrow">{eyebrow}</div>}
        <h1>{title}</h1>
        {description && <p>{description}</p>}
      </div>
      {action}
    </div>
  );
}
function Card({
  children,
  className = "",
  ...props
}: {
  children: ReactNode;
  className?: string;
} & ComponentProps<"section">) {
  return (
    <section className={`card ${className}`} {...props}>
      {children}
    </section>
  );
}
function LoadingCard() {
  return (
    <Card>
      <div className="loading">
        <span className="spinner" />
        Loading workspace data…
      </div>
    </Card>
  );
}
function ErrorBox({ error }: { error: string }) {
  return (
    <div className="alert error" role="alert">
      <CircleHelp size={17} />
      <span>{error}</span>
    </div>
  );
}
function Empty({
  title,
  detail,
  icon: Icon = Folder,
}: {
  title: string;
  detail: string;
  icon?: typeof Folder;
}) {
  return (
    <div className="empty">
      <span className="empty-icon">
        <Icon size={23} />
      </span>
      <b>{title}</b>
      <p>{detail}</p>
    </div>
  );
}
function useResource<T>(path: string | undefined) {
  const [data, setData] = useState<T | null>(() =>
    path ? (getCachedResource<T>(path) ?? null) : null,
  ),
    [error, setError] = useState(""),
    [loading, setLoading] = useState(
      !!path && getCachedResource<T>(path) === undefined,
    ),
    [revision, setRevision] = useState(0);
  useEffect(() => {
    let active = true;
    if (!path) {
      setData(null);
      setError("");
      setLoading(false);
      return;
    }
    const cached = getCachedResource<T>(path);
    setData(cached ?? null);
    setError("");
    setLoading(cached === undefined);
    const subscription: ResourceSubscription = {
      path,
      invalidate: () => setRevision((value) => value + 1),
    };
    resourceSubscriptions.add(subscription);
    if (revision === 0 && cached !== undefined) {
      setLoading(false);
      return () => {
        active = false;
        resourceSubscriptions.delete(subscription);
      };
    }
    setLoading(true);
    loadResource<T>(path, revision > 0)
      .then((v) => {
        if (active) {
          setData(v);
          setError("");
        }
      })
      .catch((e) => {
        if (active)
          setError(e instanceof Error ? e.message : "Could not load data.");
      })
      .finally(() => {
        if (active) setLoading(false);
      });
    return () => {
      active = false;
      resourceSubscriptions.delete(subscription);
    };
  }, [path, revision]);
  return {
    data,
    error,
    loading,
    setData,
    refresh: () => setRevision((value) => value + 1),
  };
}
function Dashboard({ user }: { user: Person }) {
  const {
    data: attempts,
    error,
    loading,
  } = useResource<unknown[]>("/tests/me/attempts");
  const rows = asArray<Data>(attempts);
  const completed = rows.filter(
    (r) =>
      String(r.attempt?.status ?? r.status ?? "")
        .toLowerCase()
        .includes("submitted") || r.attempt?.marksObtained != null,
  ).length;
  return (
    <>
      <Heading
        eyebrow="OVERVIEW"
        title={`Good to see you${user.fullName ? `, ${user.fullName.split(" ")[0]}` : ""}`}
        description="Your learning space, organized around what matters next."
      />
      <div className="welcome-banner">
        <div>
          <div className="banner-label">
            <Sparkles size={15} /> KEEP YOUR MOMENTUM
          </div>
          <h2>Small steps. Strong progress.</h2>
          <p>
            Pick up a test, explore your notebook, or see how your work is
            adding up.
          </p>
        </div>
        <div className="banner-art">
          <div className="art-ring ring-a" />
          <div className="art-ring ring-b" />
          <BookOpen size={45} />
        </div>
      </div>
      <div className="metric-grid">
        <Metric
          icon={FileText}
          label="Available tests"
          value={loading ? "—" : String(rows.length)}
          tone="blue"
        />
        <Metric
          icon={Check}
          label="Completed"
          value={loading ? "—" : String(completed)}
          tone="green"
        />
        <Metric
          icon={Clock3}
          label="Next deadline"
          value="See My Tests"
          tone="amber"
        />
      </div>
      <div className="section-head">
        <div>
          <h2>Continue learning</h2>
          <p>Your latest test activity from the server</p>
        </div>
      </div>
      {loading ? (
        <LoadingCard />
      ) : error ? (
        <ErrorBox error={error} />
      ) : (
        <Card className="activity-card">
          {rows.length ? (
            rows
              .slice(0, 4)
              .map((r, i) => <TestRow key={getId(r.test ?? r) || i} row={r} />)
          ) : (
            <Empty
              title="Nothing assigned yet"
              detail="Tests and attempts assigned to your account will appear here."
              icon={FileText}
            />
          )}
        </Card>
      )}
    </>
  );
}
function Metric({
  icon: Icon,
  label: caption,
  value,
  tone,
}: {
  icon: typeof FileText;
  label: string;
  value: string;
  tone: string;
}) {
  return (
    <Card className="metric">
      <span className={`metric-icon ${tone}`}>
        <Icon size={19} />
      </span>
      <div>
        <span>{caption}</span>
        <strong>{value}</strong>
      </div>
    </Card>
  );
}
function TestRow({ row, onClick }: { row: Data; onClick?: () => void }) {
  const t = row.test ?? row;
  const a = row.attempt ?? {};
  const deadline = formatDateTime(row.effectiveDeadlineAt ?? t.deadlineAt);
  const status = t.archivedAt
    ? "Archived · history"
    : a.submittedAt && a.isResultReleased === false
    ? "Submitted · Under marking"
    : String(
    row.status ??
      a.status ??
      (a.marksObtained != null ? "Reviewed" : "Not started"),
  ).replace(/_/g, " ");
  return (
    <div
      className="test-row"
      onClick={onClick}
      onKeyDown={(e) => {
        if (onClick && (e.key === "Enter" || e.key === " ")) onClick();
      }}
      role={onClick ? "button" : undefined}
      tabIndex={onClick ? 0 : undefined}
    >
      <span className="row-icon">
        <FileText size={18} />
      </span>
      <div className="row-main">
        <b>{getTitle(t)}</b>
        <small>
          {[t.description, deadline !== "—" ? `Expires ${deadline}` : ""]
            .filter(Boolean)
            .join(" · ") || "No expiration date"}
        </small>
      </div>
      <span
        className={`status ${status.toLowerCase().includes("submit") || status.toLowerCase().includes("review") ? "success" : ""}`}
      >
        {status}
      </span>
      <span className="row-date">
        {a.marksObtained != null
          ? `${a.marksObtained} marks`
          : formatDate(t.deadlineAt)}
      </span>
      <ChevronRight size={17} className="row-chevron" />
    </div>
  );
}
function StudentTests() {
  const { data, error, loading } = useResource<unknown[]>("/tests/me/attempts");
  const rows = asArray<Data>(data);
  const [selected, setSelected] = useState("");
  return (
    <>
      <Heading
        eyebrow="ASSESSMENTS"
        title="My Tests"
        description="Your assigned tests, attempt history, and results."
      />
      <div className="metric-grid compact">
        <Metric
          icon={FileText}
          label="Assigned"
          value={loading ? "—" : String(rows.length)}
          tone="blue"
        />
        <Metric
          icon={Check}
          label="Submitted"
          value={String(
            rows.filter((r) =>
              String(r.attempt?.status ?? "")
                .toLowerCase()
                .includes("submit"),
            ).length,
          )}
          tone="green"
        />
        <Metric
          icon={Trophy}
          label="Reviewed"
          value={String(
            rows.filter((r) => r.attempt?.marksObtained != null).length,
          )}
          tone="amber"
        />
      </div>
      {error ? (
        <ErrorBox error={error} />
      ) : loading ? (
        <LoadingCard />
      ) : selected ? (
        <TestAttempt testId={selected} onBack={() => setSelected("")} />
      ) : (
        <Card>
          <div className="card-heading">
            <div>
              <h2>Assigned to you</h2>
              <p>Attempts and due dates are synced from your account.</p>
            </div>
            <span className="count-badge">{rows.length} tests</span>
          </div>
          {rows.length ? (
            rows.map((r, i) => (
              <TestRow
                key={getId(r.test ?? r) || i}
                row={r}
                onClick={() => setSelected(getId(r.test ?? r))}
              />
            ))
          ) : (
            <Empty
              title="No tests found"
              detail="When a test is assigned, it will appear here."
              icon={FileText}
            />
          )}
        </Card>
      )}
    </>
  );
}
function TestAttempt({
  testId,
  onBack,
}: {
  testId: string;
  onBack: () => void;
}) {
  const { data, error, loading, refresh, setData } = useResource<Data>(
    `/tests/${testId}/me`,
  );
  const draftKey = `nexus.answer-draft:${getTokenSubject() ?? "unknown"}:${testId}`;
  const submissionKey = `nexus.submission:${getTokenSubject() ?? "unknown"}:${testId}`;
  const initialAnswers = (() => {
    try {
      const saved = localStorage.getItem(draftKey);
      const parsed = saved ? JSON.parse(saved) : null;
      return parsed && typeof parsed === "object" && parsed.answers
        ? (parsed.answers as Record<number, string>)
        : {};
    } catch {
      return {};
    }
  })();
  const [index, setIndex] = useState(0),
    [answers, setAnswers] = useState<Record<number, string>>(initialAnswers),
    [questionFiles, setQuestionFiles] = useState<
      Record<number, File | undefined>
    >({}),
    [paperFile, setPaperFile] = useState<File | undefined>(),
    [busy, setBusy] = useState(false),
    [gradingQueued, setGradingQueued] = useState(false),
    [gradingPolls, setGradingPolls] = useState(0),
    [message, setMessage] = useState(""),
    [fontFamily, setFontFamily] = useState("Arial"),
    [fontSize, setFontSize] = useState("16px"),
    [textAlign, setTextAlign] = useState<"left" | "center" | "right">("left");
  const answerEditor = useRef<HTMLDivElement>(null);
  const latestAnswers = useRef(answers);
  latestAnswers.current = answers;
  const lastSavedDraft = useRef("");
  const lastDraftSavedAt = useRef(0);
  const submissionId = useRef<string | null>(
    localStorage.getItem(submissionKey),
  );
  const questions = asArray<Data>(data?.questions);
  const question = questions[index];
  const questionKey = Number(question?.questionNumber ?? index + 1);
  const canSubmit = Boolean(
    paperFile ||
    (questions.length > 0 &&
      questions.every((item, itemIndex) => {
        const number = Number(item.questionNumber ?? itemIndex + 1);
        return Boolean(questionFiles[number] || answers[number]?.trim());
      })),
  );
  useEffect(() => {
    if (answerEditor.current) {
      answerEditor.current.innerText = answers[questionKey] ?? "";
    }
  }, [questionKey]);
  useEffect(() => {
    const savedAnswers = asArray<Data>(data?.draftAnswers).reduce<
      Record<number, string>
    >((result, answer) => {
      const number = Number(answer.questionNumber);
      if (number > 0) result[number] = String(answer.answerText ?? "");
      return result;
    }, {});
    if (Object.keys(savedAnswers).length > 0) {
      setAnswers((current) =>
        Object.keys(current).length === 0 ? savedAnswers : current,
      );
    }
  }, [data?.draftAnswers]);

  const saveDraft = (keepalive = false) => {
    const current = latestAnswers.current;
    if (Object.keys(current).length === 0) return;
    const answersJson = JSON.stringify(
      Object.entries(current).map(([questionNumber, answerText]) => ({
        questionNumber: Number(questionNumber),
        answerText,
      })),
    );
    if (
      answersJson === lastSavedDraft.current ||
      Date.now() - lastDraftSavedAt.current < 60_000
    ) {
      return;
    }
    if (keepalive && answersJson.length > 50_000) return;
    const request = json("PUT", { answers: answersJson });
    if (keepalive) request.keepalive = true;
    void api<Data>(`/tests/${testId}/me/draft`, request)
      .then((result) => {
        if (result?.saved) {
          lastSavedDraft.current = answersJson;
          lastDraftSavedAt.current = Date.now();
        }
      })
      .catch(() => {
        // The local draft remains available when the server cannot be reached.
      });
  };
  useEffect(() => {
    if (Object.keys(answers).length === 0) return;
    try {
      localStorage.setItem(
        draftKey,
        JSON.stringify({ answers, savedAt: new Date().toISOString() }),
      );
    } catch {
      // The form remains usable when storage is disabled or full.
    }
    const timer = window.setTimeout(
      () => saveDraft(),
      10_000 + Math.round(Math.random() * 5_000),
    );
    return () => window.clearTimeout(timer);
  }, [answers, draftKey]);
  useEffect(() => {
    const onPageHide = () => saveDraft(true);
    const timer = window.setInterval(() => saveDraft(), 60_000);
    window.addEventListener("pagehide", onPageHide);
    return () => {
      window.clearInterval(timer);
      window.removeEventListener("pagehide", onPageHide);
    };
  }, [testId]);
  useEffect(() => {
    if (!gradingQueued) return;
    if (
      data?.attempt?.gradingComplete === true ||
      data?.attempt?.status === "COMPLETED"
    ) {
      setGradingQueued(false);
      refresh();
      setMessage(
        `Your answers were assessed.${data.attempt.resultAvailableAt ? ` Results will be available ${formatDateTime(data.attempt.resultAvailableAt)}.` : ""}`,
      );
      return;
    }
    let timer: number | undefined;
    let active = true;
    const checkStatus = () => {
      if (!active || document.visibilityState !== "visible") return;
      setGradingPolls((count) => count + 1);
      void api<Data>(`/tests/${testId}/me/status`)
        .then((status) => {
          if (!active || !status) return;
          if (status.gradingComplete === true) {
            setData((current) =>
              current
                ? {
                    ...current,
                    attempt: {
                      ...current.attempt,
                      status: "COMPLETED",
                      gradingComplete: true,
                    },
                  }
                : current,
            );
            return;
          }
          setData((current) =>
            current
              ? {
                  ...current,
                  attempt: {
                    ...current.attempt,
                    status: status.status,
                    gradingComplete: false,
                    retryAfterSeconds: status.retryAfterSeconds,
                  },
                }
              : current,
          );
        })
        .catch(() => {
          // A temporary network error does not mean the saved submission failed.
        });
    };
    const schedule = () => {
      if (timer !== undefined) window.clearTimeout(timer);
      if (document.visibilityState !== "visible") return;
      const base = gradingPolls < GRADING_FAST_POLLS ? 20 : 60;
      const recommended = Number(data?.attempt?.retryAfterSeconds ?? 20);
      const jittered = Math.round(
        Math.max(base, recommended, 20) * (0.8 + Math.random() * 0.4) * 1000,
      );
      timer = window.setTimeout(checkStatus, Math.max(20_000, jittered));
    };
    const onVisibilityChange = () => {
      if (document.visibilityState === "visible") {
        checkStatus();
      } else if (timer !== undefined) {
        window.clearTimeout(timer);
      }
    };
    schedule();
    document.addEventListener("visibilitychange", onVisibilityChange);
    return () => {
      active = false;
      if (timer !== undefined) window.clearTimeout(timer);
      document.removeEventListener("visibilitychange", onVisibilityChange);
    };
  }, [
    gradingQueued,
    gradingPolls,
    data?.attempt?.status,
    data?.attempt?.gradingComplete,
    data?.attempt?.retryAfterSeconds,
  ]);
  function applyTextFormat(command: "bold" | "italic" | "underline") {
    answerEditor.current?.focus();
    document.execCommand(command);
  }
  async function submit() {
    if (!canSubmit) {
      setMessage(
        "Answer every question or upload one complete paper before submitting.",
      );
      return;
    }
    setBusy(true);
    setMessage("");
    try {
      const form = new FormData();
      form.append(
        "answers",
        JSON.stringify(
          questions.map((q, i) => ({
            questionNumber: Number(q.questionNumber ?? i + 1),
            answerText: answers[Number(q.questionNumber ?? i + 1)] ?? "",
          })),
        ),
      );
      if (paperFile) {
        await appendAnswerFile(form, paperFile, "paper");
      } else {
        for (const [number, file] of Object.entries(questionFiles)) {
          if (file) await appendAnswerFile(form, file, `question-${number}`);
        }
      }
      submissionId.current ??= crypto.randomUUID();
      localStorage.setItem(submissionKey, submissionId.current);
      form.append("clientSubmissionId", submissionId.current);
      let result: Data | undefined;
      const retryDelays = [1_000, 3_000, 8_000, 15_000];
      for (let attempt = 0; attempt <= retryDelays.length; attempt += 1) {
        try {
          result = await api<Data>(`/tests/${testId}/me/submit`, {
            method: "POST",
            body: form,
          });
          break;
        } catch (error) {
          const retryable =
            error instanceof ApiError &&
            [0, 429, 502, 503, 504, 508].includes(error.status);
          if (!retryable || attempt === retryDelays.length) {
            if (retryable) {
              const isLimit = error instanceof ApiError && (error.status === 508 || error.status === 429);
              setMessage(
                isLimit
                  ? "The server is temporarily busy (Resource Limit reached). All your answers and uploaded files remain safely saved on your device. Please wait 1 to 2 minutes, then click Submit again."
                  : "Still sending. Your written answers and files are saved on this device. Keep this page open and try again shortly.",
              );
              return;
            }
            throw error;
          }
          setMessage(
            "Still sending. Your written answers are saved on this device.",
          );
          await new Promise((resolve) =>
            window.setTimeout(resolve, retryDelays[attempt]),
          );
        }
      }
      if (!result) {
        setMessage(
          "Still sending. Your written answers are saved on this device. Keep this page open and try again shortly.",
        );
        return;
      }
      localStorage.removeItem(submissionKey);
      localStorage.removeItem(draftKey);
      submissionId.current = null;
      const queued = Boolean(result.gradingQueued);
      setMessage(
        queued
          ? `Saved at ${new Date(result.submittedAt ?? Date.now()).toLocaleTimeString([], { hour: "2-digit", minute: "2-digit" })}. Your answers are safe. Your result will appear in My Tests when it has been checked. You can close this page.`
          : result.gradingPending
          ? "Your answers are saved. A teacher will check them."
          : `Your answers were saved and assessed.${result.resultAvailableAt ? ` Results will be available ${formatDateTime(result.resultAvailableAt)}.` : ""}`,
      );
      setGradingQueued(queued);
      setGradingPolls(0);
      invalidateResourceCache("my-tests");
      refresh();
    } catch (e) {
      if (
        (e instanceof ApiError && (e.status === 508 || e.status === 429)) ||
        (e instanceof Error && /508|resource limit/i.test(e.message))
      ) {
        setMessage(
          "The server is temporarily busy (Resource Limit reached). All your answers and uploaded files remain safely saved on your device. Please wait 1 to 2 minutes, then click Submit again.",
        );
      } else {
        setMessage(e instanceof Error ? e.message : "Submission failed.");
      }
    } finally {
      setBusy(false);
    }
  }
  async function requestAccess() {
    if (!window.confirm("Send an access request to the admin for this test?"))
      return;
    setBusy(true);
    try {
      await api(`/tests/${testId}/me/request-access`, { method: "POST" });
      setMessage("Access request sent.");
      invalidateResourceCache("my-tests");
      await refresh();
    } catch (e) {
      setMessage(e instanceof Error ? e.message : "Request failed.");
    } finally {
      setBusy(false);
    }
  }
  if (loading) return <LoadingCard />;
  if (error)
    return (
      <>
        <button className="back-link" onClick={onBack}>
          <ArrowLeft size={16} /> Back to tests
        </button>
        <ErrorBox error={error} />
        <Card className="inline-empty">
          <Empty
            title="Test is unavailable"
            detail="The API did not return test details. You can request access if this assignment is not yet available."
            icon={ShieldCheck}
          />
          <button
            className="button secondary"
            onClick={() => void requestAccess()}
            disabled={busy}
          >
            Request access
          </button>
        </Card>
      </>
    );
  const submitted = Boolean(
    data?.attempt?.submittedAt ||
    ["submitted", "completed", "failed"].includes(
      String(data?.attempt?.status ?? "").toLowerCase(),
    ),
  );
  const expired = Boolean(
    !submitted &&
    data?.effectiveDeadlineAt &&
    Date.parse(String(data.effectiveDeadlineAt)) <= Date.now(),
  );
  const accessRequestPending =
    String(data?.attempt?.status ?? "").toUpperCase() === "REQUESTED";
  return (
    <>
      <button className="back-link" onClick={onBack}>
        <ArrowLeft size={16} /> Back to tests
      </button>
      <Heading
        eyebrow="TEST ATTEMPT"
        title={getTitle(data ?? {})}
        description={[
          data?.description ||
            "Work through questions in order, then submit your answers.",
          data?.effectiveDeadlineAt
            ? `Expires ${formatDateTime(data.effectiveDeadlineAt)}`
            : "",
        ]
          .filter(Boolean)
          .join(" · ")}
      />
      {message && (
        <div
          className={`alert ${message.includes("submitted") || gradingQueued ? "success" : "error"}`}
        >
          {message}
        </div>
      )}
      {submitted ? (
        <>
          <Card>
            <div className="result-panel">
              <span className="result-check">
                <Check />
              </span>
              <h2>
                Submission status: {data?.attempt?.submittedAt &&
                data?.attempt?.isResultReleased === false
                  ? "Submitted — under marking"
                  : label(data?.attempt?.status, "Submitted")}
              </h2>
              <p>
                {data?.attempt?.marksObtained != null
                  ? `Result: ${data.attempt.marksObtained} / ${data?.totalMarks ?? "—"} marks`
                  : String(data?.attempt?.status ?? "").toLowerCase() ===
                    "failed"
                  ? "Your answers are saved. A teacher will check them."
                  : ["submitted", "processing"].includes(
                        String(data?.attempt?.status ?? "").toLowerCase(),
                      )
                    ? "Your answers are safe. Your result will appear in My Tests when it has been checked. You can close this page."
                      : `Your test has been submitted and is under marking.${data?.attempt?.resultAvailableAt ? ` Results are scheduled for ${formatDateTime(data.attempt.resultAvailableAt)}.` : " The administrator will finish the review."}`}
              </p>
              <div className="button-row result-exports">
                <button
                  className="button secondary small-button"
                  disabled={!questions.length}
                  onClick={() =>
                    void exportTestQuestions({
                      ...data,
                      questions,
                      title: getTitle(data ?? {}),
                    })
                  }
                >
                  <Download size={15} /> Export question paper
                </button>
                {data?.attempt?.marksObtained != null &&
                  asArray<Data>(data?.attempt?.aiAssessment?.questions).length >
                    0 && (
                    <button
                      className="button secondary small-button"
                      onClick={() =>
                        void exportTestSolutions(
                          getTitle(data ?? {}),
                          data?.attempt?.aiAssessment,
                        )
                      }
                    >
                      <Download size={15} /> Export solutions
                    </button>
                  )}
              </div>
            </div>
            {(data?.attempt?.aiAssessment?.deficiencyReport ??
              data?.attempt?.deficiencyNotes ??
              asArray<Data>(data?.attempt?.aiAssessment?.questions).length >
                0) && (
              <div className="report-panel">
                <div className="card-heading">
                  <div>
                    <h2>Feedback report</h2>
                    <p>Generated from your answers and the marking scheme.</p>
                  </div>
                  <button
                    className="button secondary small-button"
                    onClick={() =>
                      void exportDeficiencyReport(
                        getTitle(data ?? {}),
                        data?.attempt?.aiAssessment ?? {},
                        String(data?.attempt?.deficiencyNotes ?? ""),
                      )
                    }
                  >
                    <Download size={15} /> Download formatted report
                  </button>
                </div>
                <FormattedContent
                  className="report-content formatted-content"
                  content={
                    data?.attempt?.aiAssessment?.deficiencyReport ??
                    data?.attempt?.deficiencyNotes
                  }
                />
                {asArray<Data>(data?.attempt?.aiAssessment?.questions).map(
                  (questionResult) => (
                    <section
                      className="question-feedback"
                      key={questionResult.questionNumber}
                    >
                      <div className="card-heading">
                        <div>
                          <h3>Question {questionResult.questionNumber}</h3>
                          <p>
                            {questionResult.marksAwarded} /{" "}
                            {questionResult.marksAvailable} marks
                          </p>
                        </div>
                      </div>
                      <FormattedContent
                        className="question-feedback-summary formatted-content"
                        content={questionResult.deficiencyReport}
                      />
                      {asArray<Data>(questionResult.criterionResults).map(
                        (criterion, criterionIndex) => (
                          <div
                            className="criterion-feedback"
                            key={`${criterion.criterion}-${criterionIndex}`}
                          >
                            <div className="criterion-heading">
                              <b>{criterion.criterion}</b>
                              <span>
                                {criterion.marksAwarded} /{" "}
                                {criterion.marksAvailable}
                              </span>
                            </div>
                            <FormattedContent
                              className="formatted-content"
                              content={criterion.feedback}
                            />
                            {asArray<string>(criterion.evidence).length > 0 && (
                              <p className="criterion-evidence">
                                <b>Your answer evidence:</b>{" "}
                                {asArray<string>(criterion.evidence)
                                  .map((item) => `“${item}”`)
                                  .join("; ")}
                              </p>
                            )}
                          </div>
                        ),
                      )}
                      {data?.attempt?.marksObtained != null && (
                        <LazyDetails
                          className="solution-details"
                          summary="View original question and ideal solution pages"
                        >
                          <h4>Original question page</h4>
                          <PageImageGallery
                            endpoint={`/api/tests/${testId}/me/source-files`}
                            emptyMessage="No original question page is mapped to this question."
                            files={sourcePageImageFiles(
                              Number(questionResult.questionNumber),
                              asArray<Data>(data.questions),
                              asArray<Data>(data.solutionSourceFiles),
                              "questionSourceLocations",
                            )}
                          />
                          <h4>Ideal solution page</h4>
                          <PageImageGallery
                            endpoint={`/api/tests/${testId}/me/source-files`}
                            emptyMessage="No original ideal-solution page is mapped to this question."
                            files={sourcePageImageFiles(
                              Number(questionResult.questionNumber),
                              asArray<Data>(data.questions),
                              asArray<Data>(data.solutionSourceFiles),
                              "solutionSourceLocations",
                            )}
                          />
                        </LazyDetails>
                      )}
                      {asArray<Data>(data?.attempt?.files).length > 0 && (
                        <LazyDetails
                          className="solution-details student-answer-pages"
                          summary="View your original submitted answer page image"
                        >
                          <PageImageGallery
                            endpoint={`/api/tests/${testId}/me/attempt-files`}
                            emptyMessage="No original PDF or image page was attached to this answer."
                            files={asArray<Data>(data?.attempt?.files)
                              .filter(
                                (file) =>
                                  file.questionNumber == null ||
                                  Number(file.questionNumber) ===
                                    Number(questionResult.questionNumber),
                              )
                              .map((file) => ({
                                id: getId(file),
                                originalName: String(
                                  file.originalName ?? "Your answer",
                                ),
                                mimeType: String(file.mimeType ?? ""),
                                ...(Array.isArray(
                                  questionResult.studentPageNumbers,
                                )
                                  ? {
                                      pageNumbers: asArray<number>(
                                        questionResult.studentPageNumbers,
                                      ),
                                    }
                                  : {}),
                              }))}
                          />
                        </LazyDetails>
                      )}
                    </section>
                  ),
                )}
              </div>
            )}
          </Card>
          {data?.attempt?.marksObtained != null && (
            <StudentReview testId={testId} />
          )}
        </>
      ) : expired ? (
        <Card className="inline-empty">
          <Empty
            title={
              accessRequestPending
                ? "Access request submitted"
                : "This test deadline has passed"
            }
            detail={
              accessRequestPending
                ? "Your request is waiting for an admin to review it. The test will become available here if a new deadline is granted."
                : "You can request a new attempt window from the admin. The request will be linked to this test and your account."
            }
            icon={accessRequestPending ? Check : Clock3}
          />
          {!accessRequestPending && (
            <button
              className="button primary"
              disabled={busy}
              onClick={() => void requestAccess()}
            >
              {busy ? "Sending request…" : "Request access"}
            </button>
          )}
        </Card>
      ) : questions.length === 0 ? (
        <Card>
          <Empty
            title="No question details available"
            detail="This test does not expose questions through the API response. Submission is disabled to prevent sending incomplete work."
            icon={FileText}
          />
        </Card>
      ) : (
        <>
          <div className="stepper">
            <button
              aria-label="Previous question"
              className="icon-button"
              disabled={index === 0}
              onClick={() => setIndex((i) => i - 1)}
            >
              <ChevronLeft />
            </button>
            <div className="step-track">
              {questions.map((q, i) => (
                <button
                  key={i}
                  aria-label={`Question ${i + 1}`}
                  onClick={() => setIndex(i)}
                  className={`step-dot ${i === index ? "current" : ""} ${answers[Number(q.questionNumber ?? i + 1)] ? "answered" : ""}`}
                >
                  {i + 1}
                </button>
              ))}
            </div>
            <button
              aria-label="Next question"
              className="icon-button"
              disabled={index === questions.length - 1}
              onClick={() => setIndex((i) => i + 1)}
            >
              <ChevronRight />
            </button>
            <span className="step-count">
              Question {index + 1} of {questions.length}
            </span>
          </div>
          <Card className="question-card question-screen">
            <div className="question-header">
              <div>
                <span className="eyebrow">
                  QUESTION {question.questionNumber ?? index + 1}
                </span>
                <FormattedContent
                  className="question-text-content formatted-content"
                  content={question.questionText}
                />
              </div>
              <span className="marks-chip">{question.marks ?? "—"} marks</span>
            </div>
            <div className="answer-label">
              <span>Your answer</span>
              <div
                className="answer-toolbar"
                role="toolbar"
                aria-label="Answer formatting"
              >
                <button
                  type="button"
                  aria-label="Bold"
                  title="Bold"
                  onMouseDown={(e) => e.preventDefault()}
                  onClick={() => applyTextFormat("bold")}
                >
                  <Bold size={16} />
                </button>
                <button
                  type="button"
                  aria-label="Italic"
                  title="Italic"
                  onMouseDown={(e) => e.preventDefault()}
                  onClick={() => applyTextFormat("italic")}
                >
                  <Italic size={16} />
                </button>
                <button
                  type="button"
                  aria-label="Underline"
                  title="Underline"
                  onMouseDown={(e) => e.preventDefault()}
                  onClick={() => applyTextFormat("underline")}
                >
                  <Underline size={16} />
                </button>
                <select
                  aria-label="Font family"
                  value={fontFamily}
                  onChange={(e) => setFontFamily(e.target.value)}
                >
                  <option>Arial</option>
                  <option>Georgia</option>
                  <option>Times New Roman</option>
                  <option>Verdana</option>
                </select>
                <select
                  aria-label="Font size"
                  value={fontSize}
                  onChange={(e) => setFontSize(e.target.value)}
                >
                  <option value="14px">Small</option>
                  <option value="16px">Normal</option>
                  <option value="20px">Large</option>
                  <option value="24px">Extra large</option>
                </select>
                <button
                  type="button"
                  aria-label="Align left"
                  title="Align left"
                  onClick={() => setTextAlign("left")}
                >
                  <AlignLeft size={16} />
                </button>
                <button
                  type="button"
                  aria-label="Align center"
                  title="Align center"
                  onClick={() => setTextAlign("center")}
                >
                  <AlignCenter size={16} />
                </button>
                <button
                  type="button"
                  aria-label="Align right"
                  title="Align right"
                  onClick={() => setTextAlign("right")}
                >
                  <AlignRight size={16} />
                </button>
              </div>
              <div
                ref={answerEditor}
                className="answer-editor"
                role="textbox"
                aria-label={`Answer to question ${questionKey}`}
                aria-multiline="true"
                contentEditable
                suppressContentEditableWarning
                onInput={(e) =>
                  setAnswers({
                    ...answers,
                    [questionKey]: e.currentTarget.innerText,
                  })
                }
                style={{ fontFamily, fontSize, textAlign }}
                data-placeholder="Write your answer or workings here…"
              />
            </div>
            <label className="upload-box">
              <input
                type="file"
                accept=".pdf,.docx,.txt,.jpg,.jpeg,.png,.webp"
                onChange={(e) =>
                  setQuestionFiles({
                    ...questionFiles,
                    [questionKey]: e.target.files?.[0],
                  })
                }
              />
              <span className="upload-symbol">
                <ArrowRight size={18} />
              </span>
              <b>Upload an answer file for Question {questionKey}</b>
              <small>
                PDF, DOCX, TXT, or an image.                 PDF pages and clear photos are reviewed automatically.
                Up to 30 MiB combined.
              </small>
              {questionFiles[questionKey] && (
                <em>{questionFiles[questionKey]?.name}</em>
              )}
            </label>
            <label className="field-label">
              Or submit one complete paper (replaces per-question uploads; up
              to 30 pages and 30 MiB). PDF pages and clear photos are checked
              through our automatic review system.
              <input
                type="file"
                accept=".pdf,.docx,.txt,.jpg,.jpeg,.png,.webp"
                onChange={(e) => setPaperFile(e.target.files?.[0])}
              />
              {paperFile && <small>{paperFile.name}</small>}
            </label>
            <div className="question-footer">
              <button
                className="button secondary"
                disabled={index === 0}
                onClick={() => setIndex((i) => i - 1)}
              >
                <ArrowLeft size={16} /> Previous
              </button>
              {index < questions.length - 1 ? (
                <button
                  className="button primary"
                  onClick={() => setIndex((i) => i + 1)}
                >
                  Next question <ArrowRight size={16} />
                </button>
              ) : (
                <button
                  className="button primary"
                  disabled={busy || !canSubmit}
                  onClick={() => void submit()}
                >
                  {busy ? "Submitting…" : "Submit test"}
                  <Check size={17} />
                </button>
              )}
            </div>
          </Card>
        </>
      )}
    </>
  );
}
function StudentReview({ testId }: { testId: string }) {
  const [note, setNote] = useState(""),
    [message, setMessage] = useState(""),
    [busy, setBusy] = useState(false);
  async function send() {
    setBusy(true);
    setMessage("");
    try {
      await api(`/tests/${testId}/me/review`, json("POST", { note }));
      setMessage("Your review response was submitted.");
      setNote("");
    } catch (e) {
      setMessage(e instanceof Error ? e.message : "Could not submit response.");
    } finally {
      setBusy(false);
    }
  }
  return (
    <Card>
      <div className="card-heading">
        <div>
          <h2>Review response</h2>
          <p>
            Send a note about the review or result through the student review
            endpoint.
          </p>
        </div>
      </div>
      <label className="field-label">
        Message
        <textarea
          rows={3}
          maxLength={4000}
          value={note}
          onChange={(e) => setNote(e.target.value)}
          placeholder="Write your response?"
        />
      </label>
      {message && (
        <div
          className={`alert ${message.includes("submitted") ? "success" : "error"}`}
        >
          {message}
        </div>
      )}
      <button
        className="button secondary"
        disabled={!note.trim() || busy}
        onClick={() => void send()}
      >
        {busy ? "Sending?" : "Submit response"}
        <Send size={15} />
      </button>
    </Card>
  );
}
function Performers() {
  const { data, error, loading } = useResource<unknown[]>("/leaderboard");
  const rows = asArray<Data>(data);
  return (
    <>
      <Heading
        eyebrow="COMMUNITY"
        title="Top Performers"
        description="A privacy-conscious leaderboard based on published results."
      />
      <Card>
        {loading ? (
          <div className="loading">
            <span className="spinner" />
            Loading rankings…
          </div>
        ) : error ? (
          <ErrorBox error={error} />
        ) : rows.length ? (
          <div className="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>Rank</th>
                  <th>Learner</th>
                  <th>Tests completed</th>
                  <th>Score</th>
                </tr>
              </thead>
              <tbody>
                {rows.map((r, i) => (
                  <tr key={getId(r) || i}>
                    <td>
                      <span className={`rank ${i < 3 ? "top" : ""}`}>
                        {r.rank ?? i + 1}
                      </span>
                    </td>
                    <td>
                      <b>{r.displayIdentifier ?? "Learner"}</b>
                      {r.isCurrentUser && <span className="you-tag">YOU</span>}
                    </td>
                    <td>{r.testsAttempted ?? "—"}</td>
                    <td>
                      {r.percentage != null
                        ? `${r.percentage}%`
                        : r.totalMarks != null
                          ? `${r.totalMarks} / ${r.maxMarks}`
                          : "—"}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        ) : (
          <Empty
            title="Rankings are not available yet"
            detail="Once results are published, available rankings will appear here."
            icon={Trophy}
          />
        )}
      </Card>
      <p className="privacy-note">
        <ShieldCheck size={15} /> Student names are hidden by the server for
        privacy.
      </p>
    </>
  );
}
function MindMapNode({ node, depth }: { node: Data; depth: number }) {
  const children = asArray<Data>(node.children);
  return (
    <li className={`mind-map-node depth-${Math.min(depth, 4)}`}>
      <div className="mind-map-label">{String(node.label ?? "Topic")}</div>
      {children.length > 0 && (
        <ul>
          {children.map((child, index) => (
            <MindMapNode
              key={`${String(child.label ?? "node")}-${index}`}
              node={child}
              depth={depth + 1}
            />
          ))}
        </ul>
      )}
    </li>
  );
}

function Notebook({
  admin,
  notify,
}: {
  admin: boolean;
  notify: (s: string) => void;
}) {
  const {
    data: subjectData,
    error: subjectError,
    loading: subjectLoading,
  } = useResource<unknown[]>("/ai-notebook/subjects");
  const subjects = asArray<Data>(subjectData);
  const [subjectId, setSubjectId] = useState("");
  useEffect(() => {
    if (!subjectId && subjects.length) setSubjectId(getId(subjects[0]));
  }, [subjects, subjectId]);
  const {
    data: sourceData,
    error: sourceError,
    loading: sourceLoading,
    refresh: refreshSources,
  } = useResource<unknown[]>(
    admin && subjectId ? `/ai-notebook/subjects/${subjectId}/sources` : undefined,
  );
  const sources = asArray<Data>(sourceData);
  const {
    data: sessionData,
    error: sessionsError,
    loading: sessionsLoading,
    refresh: refreshSessions,
  } = useResource<unknown[]>(
    admin
      ? subjectId
        ? `/ai-notebook/sessions?subjectId=${encodeURIComponent(subjectId)}`
        : undefined
      : "/ai-notebook/sessions",
  );
  const sessions = asArray<Data>(sessionData);
  const [question, setQuestion] = useState(""),
    [chatError, setChatError] = useState(""),
    [chatStatus, setChatStatus] = useState(""),
    [chatBusy, setChatBusy] = useState(false),
    [sessionId, setSessionId] = useState(""),
    [messages, setMessages] = useState<Data[]>([]),
    [notebookView, setNotebookView] = useState<"chat" | "history">("chat"),
    [studyAidMode, setStudyAidMode] = useState(""),
    [chatImage, setChatImage] = useState<File | null>(null),
    [uploading, setUploading] = useState(false),
    [uploadTitle, setUploadTitle] = useState("");
  const [prompt, setPrompt] = useState(""),
    [promptLanguage, setPromptLanguage] = useState("english"),
    [promptMessage, setPromptMessage] = useState("");
  const { data: promptData } = useResource<Data>(
    subjectId ? `/ai-notebook/subjects/${subjectId}/prompt` : undefined,
  );
  useEffect(() => {
    if (promptData) {
      setPrompt(String(promptData.prompt ?? ""));
      setPromptLanguage(String(promptData.responseLanguage ?? "english"));
    }
  }, [promptData]);
  async function chat(e: FormEvent) {
    e.preventDefault();
    if (!subjectId || (!question.trim() && !chatImage)) return;
    setChatBusy(true);
    setChatError("");
    setChatStatus("");
    const clientRequestId = window.crypto.randomUUID();
    const startedAt = Date.now();
    const statusTimer = window.setTimeout(
      () => setChatStatus("Still working on it, thank you for your patience."),
      20_000,
    );
    try {
      const asked = question.trim();
      let firstRequest: RequestInit;
      if (chatImage) {
        const form = new FormData();
        form.append("subjectId", subjectId);
        form.append("clientRequestId", clientRequestId);
        form.append("question", asked);
        form.append("image", chatImage);
        if (sessionId) form.append("sessionId", sessionId);
        if (studyAidMode) form.append("studyAidMode", studyAidMode);
        firstRequest = { method: "POST", body: form };
      } else {
        firstRequest = json("POST", {
          subjectId,
          clientRequestId,
          question: asked,
          ...(sessionId ? { sessionId } : {}),
          ...(studyAidMode ? { studyAidMode } : {}),
        });
      }
      let result: Data | undefined;
      const aiWorkerUrl = (import.meta.env.VITE_AI_WORKER_URL || "").replace(/\/$/, "");
      if (aiWorkerUrl && !chatImage) {
        try {
          const workerRes = await fetch(`${aiWorkerUrl}/chat`, {
            method: "POST",
            headers: {
              "Content-Type": "application/json",
              Authorization: `Bearer ${getToken()}`,
            },
            body: JSON.stringify({
              subjectId,
              question: asked,
              ...(sessionId ? { sessionId } : {}),
              ...(studyAidMode ? { studyAidMode } : {}),
            }),
          });
          if (workerRes.ok) {
            result = (await workerRes.json()) as Data;
          } else {
            const errData = (await workerRes.json().catch(() => ({}))) as Record<string, unknown>;
            if (workerRes.status === 401 || workerRes.status === 403 || workerRes.status === 429) {
              throw new Error(String(errData.message || `Request failed (${workerRes.status})`));
            }
          }
        } catch (workerErr) {
          if (workerErr instanceof Error && /authentication|permission|quota|limit/i.test(workerErr.message)) {
            throw workerErr;
          }
        }
      }

      if (!result) {
        let retries = 0;
        let ticketAccepted = false;
        while (true) {
        try {
          result = await api<Data>(
            "/ai-notebook/chat",
            ticketAccepted
              ? json("POST", { clientRequestId })
              : firstRequest,
          );
        } catch (ex) {
          const retryable =
            ex instanceof ApiError &&
            (ex.status === 0 || ex.status >= 500);
          if (!retryable) throw ex;
          retries += 1;
          const delay = Math.min(6000, 3000 + (retries % 4) * 1000);
          await new Promise((resolve) =>
            window.setTimeout(resolve, delay),
          );
          continue;
        }
        const retryAfterSeconds = Number(result.retryAfterSeconds);
        if (Number.isFinite(retryAfterSeconds) && retryAfterSeconds > 0) {
          ticketAccepted = true;
          retries += 1;
          const backoff = Math.min(6000, 3000 + (retries % 4) * 1000);
          const serverDelay = Math.min(6000, retryAfterSeconds * 1000);
          const delay = Math.max(3000, Math.min(6000, Math.max(backoff, serverDelay)));
          await new Promise((resolve) =>
            window.setTimeout(resolve, delay),
          );
          continue;
        }
        break;
      }
      }
      if (!result) {
        throw new Error("Could not get a response from the notebook.");
      }
      const response = firstReadableText(
        result.answer,
        result.response,
        result.message,
        result.content,
      );
      if (!response) {
        throw new Error(
          "The notebook returned a response without readable answer text. Please try again.",
        );
      }
      setMessages((current) => [
        ...current,
        { role: "user", content: asked },
        { role: "assistant", content: response, studyAid: result.studyAid },
      ]);
      setQuestion("");
      setChatImage(null);
      if (result.sessionId) setSessionId(result.sessionId);
      setChatStatus("");
      void refreshSessions();
    } catch (ex) {
      setChatError(
        ex instanceof Error ? ex.message : "Could not get a response.",
      );
    } finally {
      window.clearTimeout(statusTimer);
      if (Date.now() - startedAt < 20_000) setChatStatus("");
      setChatBusy(false);
    }
  }
  async function openSession(id: string) {
    try {
      const session = await api<Data>(`/ai-notebook/sessions/${id}`);
      const loadedMessages = asArray<Data>(session.messages);
      if (session.subjectId) setSubjectId(String(session.subjectId));
      setSessionId(id);
      setMessages(loadedMessages);
      setNotebookView("chat");
    } catch (ex) {
      notify(
        ex instanceof Error ? ex.message : "Could not open notebook session.",
      );
    }
  }
  function exportSession() {
    if (!messages.length) return;
    const transcript = messages
      .map((message) => {
        const content = String(message.content ?? "");
        const studyAid = message.studyAid;
        const aid =
          studyAid?.type === "mind_map"
            ? `\n\nMind map JSON:\n\`\`\`json\n${JSON.stringify({ title: studyAid.title, branches: studyAid.branches }, null, 2)}\n\`\`\``
            : "";
        return `## ${message.role === "user" ? "You" : "Notebook"}\n\n${content}${aid}`;
      })
      .join("\n\n");
    downloadText(
      `nexus-notebook-${sessionId || "session"}.md`,
      transcript,
      "text/markdown;charset=utf-8",
    );
  }
  function exportMindMap(studyAid: Data) {
    exportMindMapSvg(studyAid);
  }
  function startNewConversation() {
    setSessionId("");
    setMessages([]);
    setChatError("");
    setNotebookView("chat");
  }
  async function upload(e: FormEvent) {
    e.preventDefault();
    const input = (e.currentTarget as HTMLFormElement).elements.namedItem(
      "sourceFile",
    ) as HTMLInputElement;
    const file = input.files?.[0];
    if (!file || !subjectId) return;
    setUploading(true);
    try {
      const form = new FormData();
      form.append("file", file);
      form.append("subjectId", subjectId);
      if (uploadTitle.trim()) form.append("title", uploadTitle.trim());
      await api<Data>("/ai-notebook/sources", { method: "POST", body: form });
      notify("Source uploaded to the selected subject.");
      input.value = "";
      setUploadTitle("");
      refreshSources();
    } catch (ex) {
      notify(ex instanceof Error ? ex.message : "Upload failed.");
    } finally {
      setUploading(false);
    }
  }
  async function savePrompt() {
    if (!subjectId) return;
    setPromptMessage("");
    try {
      await api(
        `/ai-notebook/subjects/${subjectId}/prompt/${admin ? "default" : "personal"}`,
        json("PUT", { prompt, responseLanguage: promptLanguage }),
      );
      setPromptMessage(admin ? "Default prompt saved." : "Your notebook preferences saved.");
    } catch (ex) {
      setPromptMessage(
        ex instanceof Error ? ex.message : "Could not save prompt.",
      );
    }
  }
  async function removeSource(id: string) {
    if (
      !window.confirm(
        "Delete this source? This affects access to its notebook content.",
      )
    )
      return;
    try {
      await api(`/ai-notebook/sources/${id}`, { method: "DELETE" });
      notify("Source removed.");
      refreshSources();
    } catch (ex) {
      notify(ex instanceof Error ? ex.message : "Could not remove source.");
    }
  }
  return (
    <>
      <Heading
        eyebrow={admin ? "CONTENT MANAGEMENT" : "STUDY TOOLS"}
        title={admin ? "AI Notebook" : "Study Notebook"}
        description={
          admin
            ? "Manage subject-specific sources and the default AI prompt."
            : "Ask questions grounded in the learning sources available to you."
        }
      />
      <p className="ai-safety-note" role="note">
        Study answers may contain mistakes or miss details. Verify important
        information with your course material or instructor, and do not share
        passwords or sensitive personal information here.
      </p>
      {subjectError ? (
        <ErrorBox error={subjectError} />
      ) : subjectLoading ? (
        <LoadingCard />
      ) : !subjects.length ? (
        <Card>
          <Empty
            title="No subjects available"
            detail="No subjects are linked to your account yet."
            icon={BookOpen}
          />
        </Card>
      ) : (
        <>
          <div className="notebook-layout">
            <div className="notebook-left">
              <Card>
                <div className="card-heading">
                  <div>
                    <h2>Subjects</h2>
                    <p>Sources are scoped to the selected subject.</p>
                  </div>
                  <Folder size={18} />
                </div>
                <div className="folder-list">
                  {subjects.map((f) => (
                    <button
                      key={getId(f)}
                      onClick={() => {
                        setSubjectId(getId(f));
                        setSessionId("");
                        setChatError("");
                        setMessages([]);
                      }}
                      className={`folder-choice ${subjectId === getId(f) ? "selected" : ""}`}
                    >
                      <Folder size={17} />
                      <span>{getTitle(f)}</span>
                      <ChevronRight size={16} />
                    </button>
                  ))}
                </div>
              </Card>
              {admin && (
                <Card>
                  <div className="card-heading">
                    <div>
                      <h2>Sources</h2>
                      <p>Available in this subject</p>
                    </div>
                    <span className="count-badge">{sources.length}</span>
                  </div>
                  {sourceLoading ? (
                    <div className="loading">
                      <span className="spinner" />
                      Loading sources…
                    </div>
                  ) : sourceError ? (
                    <ErrorBox error={sourceError} />
                  ) : sources.length ? (
                    sources.map((s) => (
                      <div className="source-row" key={getId(s)}>
                        <span className="row-icon">
                          <FileText size={17} />
                        </span>
                        <div>
                          <b>{s.title ?? s.originalName ?? getTitle(s)}</b>
                          <small>
                            {s.status === "failed"
                              ? `Indexing failed: ${s.errorMessage ?? "Check the cloud embedding configuration."}`
                              : s.status === "processing"
                                ? `Indexing ${s.completedChunks ?? 0}/${s.totalChunks ?? 0} chunks`
                                : (s.status ?? s.fileName ?? "Notebook source")}
                          </small>
                        </div>
                        {admin && (
                          <button
                            className="icon-button danger-icon"
                            title="Remove source"
                            onClick={() => void removeSource(getId(s))}
                          >
                            <X size={16} />
                          </button>
                        )}
                      </div>
                    ))
                  ) : (
                    <p className="subtle-padding">
                      No sources are available in this subject.
                    </p>
                  )}
                </Card>
              )}
            </div>
            <div className="notebook-right">
              <div
                className="notebook-tabs"
                role="tablist"
                aria-label="Notebook views"
              >
                <button
                  type="button"
                  role="tab"
                  id="notebook-tab-chat"
                  aria-controls="notebook-panel-chat"
                  aria-selected={notebookView === "chat"}
                  className={notebookView === "chat" ? "active" : ""}
                  onClick={() => setNotebookView("chat")}
                >
                  Notebook
                </button>
                <button
                  type="button"
                  role="tab"
                  id="notebook-tab-history"
                  aria-controls="notebook-panel-history"
                  aria-selected={notebookView === "history"}
                  className={notebookView === "history" ? "active" : ""}
                  onClick={() => setNotebookView("history")}
                >
                  History <span>{sessions.length}</span>
                </button>
              </div>
              {notebookView === "history" ? (
                <Card
                  className="notebook-history"
                  id="notebook-panel-history"
                  role="tabpanel"
                  aria-labelledby="notebook-tab-history"
                >
                  <div className="card-heading">
                    <div>
                      <h2>Conversation history</h2>
                      <p>
                        {admin
                          ? "Chats in this subject"
                          : "Your conversations across accessible subjects"}
                      </p>
                    </div>
                    <button
                      className="button primary small-button"
                      onClick={startNewConversation}
                    >
                      <Plus size={15} /> New chat
                    </button>
                  </div>
                  {sessionsError && <ErrorBox error={sessionsError} />}
                  {sessionsLoading ? (
                    <div className="loading">
                      <span className="spinner" />
                      Loading conversations…
                    </div>
                  ) : sessions.length ? (
                    <div className="notebook-history-list">
                      {sessions.map((session) => {
                        const subject = subjects.find(
                          (item) =>
                            getId(item) === String(session.subjectId ?? ""),
                        );
                        return (
                          <button
                            type="button"
                            className={`notebook-history-item ${sessionId === getId(session) ? "selected" : ""}`}
                            key={getId(session)}
                            onClick={() => void openSession(getId(session))}
                          >
                            <span className="row-icon">
                              <MessageCircle size={16} />
                            </span>
                            <span className="notebook-history-copy">
                              <b>
                                {String(
                                  session.title ?? "Notebook conversation",
                                )}
                              </b>
                              <small>
                                {getTitle(subject ?? {})} ·{" "}
                                {formatDate(session.updatedAt)}
                              </small>
                            </span>
                            <ChevronRight size={16} />
                          </button>
                        );
                      })}
                    </div>
                  ) : (
                    <Empty
                      title="No conversations yet"
                      detail="Your notebook chats will appear here."
                      icon={MessageCircle}
                    />
                  )}
                </Card>
              ) : (
                <Card
                  className="chat-card"
                  id="notebook-panel-chat"
                  role="tabpanel"
                  aria-labelledby="notebook-tab-chat"
                >
                  <div className="chat-head">
                    <span className="chat-icon">
                      <Sparkles size={18} />
                    </span>
                    <div>
                      <h2>Ask your notebook</h2>
                      <p>Responses are generated by the configured API.</p>
                    </div>
                    <span className="live-label">CONNECTED</span>
                  </div>
                  <div className="chat-output">
                    {chatError && <ErrorBox error={chatError} />}
                    {chatStatus && (
                      <p className="muted" role="status" aria-live="polite">
                        {chatStatus}
                      </p>
                    )}
                    {messages.length ? (
                      messages.map((message, index) => (
                        <div
                          className={
                            message.role === "user"
                              ? "user-bubble"
                              : "answer-bubble"
                          }
                          key={`${message.role}-${index}`}
                        >
                          <div className="answer-by">
                            {message.role === "user" ? (
                              "YOU"
                            ) : (
                              <>
                                <Sparkles size={14} /> NOTEBOOK RESPONSE
                              </>
                            )}
                          </div>
                          {message.role === "user" ? (
                            <p>{String(message.content ?? "")}</p>
                          ) : (
                            <FormattedContent
                              className="formatted-content chat-message-content"
                              content={message.content}
                            />
                          )}
                          {message.studyAid?.type === "mind_map" && (
                            <div className="study-aid-result">
                              <h3>
                                {String(message.studyAid.title ?? "Mind map")}
                              </h3>
                              <div
                                className="mind-map"
                                aria-label="Generated mind map"
                              >
                                <div className="mind-map-root">
                                  {String(
                                    message.studyAid.title ?? "Main topic",
                                  )}
                                </div>
                                <ul className="mind-map-branches">
                                  {asArray<Data>(message.studyAid.branches).map(
                                    (branch, branchIndex) => (
                                      <MindMapNode
                                        key={`${String(branch.label ?? "branch")}-${branchIndex}`}
                                        node={branch}
                                        depth={1}
                                      />
                                    ),
                                  )}
                                </ul>
                              </div>
                              <button
                                className="button secondary small-button"
                                onClick={() => exportMindMap(message.studyAid)}
                              >
                                <Download size={15} /> Export mind map
                              </button>
                            </div>
                          )}
                          {message.studyAid?.type === "summary" && (
                            <span className="status success">
                              Study summary
                            </span>
                          )}
                        </div>
                      ))
                    ) : (
                      <div className="chat-placeholder">
                        <span className="chat-placeholder-icon">
                          <MessageCircle size={25} />
                        </span>
                        <b>What would you like to understand?</b>
                        <p>Ask a question about the sources in this subject.</p>
                      </div>
                    )}
                  </div>
                  <div className="chat-tools">
                    {messages.length > 0 && (
                      <button
                        className="button secondary small-button"
                        onClick={exportSession}
                      >
                        <Download size={15} /> Export chat
                      </button>
                    )}
                    <div
                      className="study-aid-controls"
                      role="group"
                      aria-label="Choose response format"
                    >
                      <span>Study aid</span>
                      {[
                        { value: "", label: "Answer" },
                        { value: "summary", label: "Summary" },
                        { value: "mind_map", label: "Mind map" },
                      ].map((option) => (
                        <button
                          key={option.value || "answer"}
                          type="button"
                          className={`study-aid-button ${studyAidMode === option.value ? "selected" : ""}`}
                          aria-pressed={studyAidMode === option.value}
                          disabled={chatBusy || (!!chatImage && !!option.value)}
                          onClick={() => setStudyAidMode(option.value)}
                        >
                          {option.label}
                        </button>
                      ))}
                    </div>
                  </div>
                  <form className="chat-form" onSubmit={chat}>
                    <textarea
                      value={question}
                      onChange={(e) => setQuestion(e.target.value)}
                      placeholder={chatImage ? "Ask about the attached photo…" : "Ask a question…"}
                      rows={2}
                      maxLength={4000}
                      disabled={chatBusy}
                    />
                    <label className="button secondary">
                      Attach photo
                      <input
                        type="file"
                        accept="image/jpeg,image/png,image/webp,.jpg,.jpeg,.png,.webp"
                        disabled={chatBusy}
                        onChange={(event) => {
                          const file = event.target.files?.[0] ?? null;
                          setChatImage(file);
                          if (file) setStudyAidMode("");
                          event.target.value = "";
                        }}
                        hidden
                      />
                    </label>
                    {chatImage && (
                      <button
                        className="button secondary"
                        type="button"
                        disabled={chatBusy}
                        onClick={() => setChatImage(null)}
                        aria-label="Remove attached photo"
                      >
                        {chatImage.name} ×
                      </button>
                    )}
                    <button
                      className="button primary"
                      disabled={chatBusy || (!question.trim() && !chatImage)}
                      aria-label={chatImage ? "Send photo question" : "Send question"}
                    >
                      {chatBusy ? "Thinking…" : <Send size={17} />}
                    </button>
                  </form>
                </Card>
              )}
              {admin && (
                <>
                  <Card>
                    <div className="card-heading">
                      <div>
                        <h2>Add source</h2>
                        <p>
                          PDF, DOCX, or TXT · scoped to the selected subject
                        </p>
                      </div>
                    </div>
                    <form className="form-stack compact-form" onSubmit={upload}>
                      <label>
                        Display title (optional)
                        <input
                          value={uploadTitle}
                          onChange={(e) => setUploadTitle(e.target.value)}
                          placeholder="Source title"
                        />
                      </label>
                      <label>
                        Source file
                        <input
                          name="sourceFile"
                          type="file"
                          accept=".pdf,.docx,.txt"
                          required
                        />
                      </label>
                      <button className="button primary" disabled={uploading}>
                        {uploading ? "Uploading…" : "Upload source"}
                        <ArrowRight size={16} />
                      </button>
                    </form>
                  </Card>
                  <Card>
                    <div className="card-heading">
                      <div>
                        <h2>Default prompt</h2>
                        <p>
                          Applied to this subject for users without a personal
                          override.
                        </p>
                      </div>
                    </div>
                    <label className="field-label">
                      Prompt
                      <textarea
                        rows={4}
                        maxLength={4000}
                        value={prompt}
                        onChange={(e) => setPrompt(e.target.value)}
                        placeholder="Set the assistant’s instructions…"
                      />
                    </label>
                    <label className="field-label small-field">
                      Response language
                      <select
                        value={promptLanguage}
                        onChange={(e) => setPromptLanguage(e.target.value)}
                      >
                        <option value="english">English</option>
                        <option value="urdu">Urdu</option>
                        <option value="roman_urdu">Roman Urdu</option>
                      </select>
                    </label>
                    {promptMessage && (
                      <div
                        className={`alert ${promptMessage.includes("saved") ? "success" : "error"}`}
                      >
                        {promptMessage}
                      </div>
                    )}
                    <button
                      className="button secondary"
                      onClick={() => void savePrompt()}
                    >
                      Save subject prompt
                    </button>
                  </Card>
                  <Card>
                    <div className="card-heading">
                      <div>
                        <h2>Notebook sessions</h2>
                        <p>Sessions visible under this subject.</p>
                      </div>
                    </div>
                    {sessions.length ? (
                      sessions.map((s) => (
                        <div className="source-row" key={getId(s)}>
                          <span className="row-icon">
                            <MessageCircle size={16} />
                          </span>
                          <div>
                            <b>
                              {s.title ??
                                s.name ??
                                `Session ${getId(s).slice(0, 8)}`}
                            </b>
                            <small>
                              {formatDate(s.updatedAt ?? s.createdAt)}
                            </small>
                          </div>
                          <button
                            className="button secondary small-button"
                            onClick={() => void openSession(getId(s))}
                          >
                            Open
                          </button>
                        </div>
                      ))
                    ) : (
                      <p className="subtle-padding">No sessions returned.</p>
                    )}
                    {!admin && (
                      <Card>
                        <div className="card-heading">
                          <div>
                            <h2>Previous sessions</h2>
                            <p>Open and export earlier conversations.</p>
                          </div>
                        </div>
                        {sessions.length ? (
                          sessions.map((session) => (
                            <div className="source-row" key={getId(session)}>
                              <span className="row-icon">
                                <MessageCircle size={16} />
                              </span>
                              <div>
                                <b>
                                  {session.title ??
                                    `Session ${getId(session).slice(0, 8)}`}
                                </b>
                                <small>
                                  {formatDate(
                                    session.updatedAt ?? session.createdAt,
                                  )}
                                </small>
                              </div>
                              <button
                                className="button secondary small-button"
                                onClick={() => void openSession(getId(session))}
                              >
                                Open
                              </button>
                            </div>
                          ))
                        ) : (
                          <p className="subtle-padding">
                            No saved sessions yet.
                          </p>
                        )}
                      </Card>
                    )}
                  </Card>
                </>
              )}
              {!admin && (
                <Card>
                  <div className="card-heading">
                    <div>
                      <h2>Notebook preferences</h2>
                      <p>
                        Choose your response language and personal instructions.
                        These apply only to your account.
                      </p>
                    </div>
                  </div>
                  <label className="field-label">
                    Personal prompt
                    <textarea
                      rows={4}
                      maxLength={4000}
                      value={prompt}
                      onChange={(event) => setPrompt(event.target.value)}
                      placeholder="Optional instructions for how the notebook should answer you…"
                    />
                  </label>
                  <label className="field-label small-field">
                    Response language
                    <select
                      value={promptLanguage}
                      onChange={(event) => setPromptLanguage(event.target.value)}
                    >
                      <option value="english">English</option>
                      <option value="urdu">Urdu</option>
                      <option value="roman_urdu">Roman Urdu</option>
                    </select>
                  </label>
                  {promptMessage && (
                    <div
                      className={`alert ${promptMessage.includes("saved") ? "success" : "error"}`}
                      role="status"
                    >
                      {promptMessage}
                    </div>
                  )}
                  <button
                    className="button secondary"
                    onClick={() => void savePrompt()}
                  >
                    Save my preferences
                  </button>
                </Card>
              )}
            </div>
          </div>
        </>
      )}
    </>
  );
}
function Academic({ canManageSources }: { canManageSources: boolean }) {
  const {
    data: subjectData,
    error: subjectError,
    loading,
    refresh: refreshSubjects,
  } = useResource<unknown[]>("/subjects/list");
  const subjects = asArray<Data>(subjectData);
  const [newSubject, setNewSubject] = useState(""),
    [subjectCode, setSubjectCode] = useState(""),
    [editingSubjectId, setEditingSubjectId] = useState(""),
    [editingSubjectName, setEditingSubjectName] = useState(""),
    [editingSubjectCode, setEditingSubjectCode] = useState(""),
    [actionError, setActionError] = useState(""),
    [busy, setBusy] = useState("");
  async function createSubject(e: FormEvent) {
    e.preventDefault();
    setBusy("subject");
    setActionError("");
    try {
      const code =
        subjectCode.trim() || `SUB-${Date.now().toString(36).toUpperCase()}`;
      await api(
        "/subjects",
        json("POST", {
          code,
          name: newSubject.trim(),
        }),
      );
      setNewSubject("");
      setSubjectCode("");
      refreshSubjects();
    } catch (ex) {
      setActionError(
        ex instanceof Error ? ex.message : "Could not create subject.",
      );
    } finally {
      setBusy("");
    }
  }
  async function saveSubject(subjectId: string) {
    setBusy("edit");
    setActionError("");
    try {
      await api(`/subjects/${subjectId}`, json("PUT", {
        name: editingSubjectName.trim(),
        code: editingSubjectCode.trim(),
      }));
      setEditingSubjectId("");
      refreshSubjects();
    } catch (ex) {
      setActionError(ex instanceof Error ? ex.message : "Could not update subject.");
    } finally {
      setBusy("");
    }
  }
  async function deleteSubject(subject: Data) {
    const name = getTitle(subject);
    if (!window.confirm(
      `Permanently delete "${name}" and all of its tests, attempts, uploaded files, Notebook sources, chats, prompts, access grants, and stored files? This cannot be undone.`,
    )) return;
    setBusy("delete");
    setActionError("");
    try {
      await api(`/subjects/${getId(subject)}`, { method: "DELETE" });
      setEditingSubjectId("");
      refreshSubjects();
    } catch (ex) {
      setActionError(ex instanceof Error ? ex.message : "Could not delete subject.");
    } finally {
      setBusy("");
    }
  }
  return (
    <>
      <Heading
        eyebrow="SUBJECTS"
        title="Subject Setup"
        description="Create and manage the subjects used to organize notebook sources and tests."
      />
      <div className="metric-grid">
        <Metric
          icon={BookOpen}
          label="Subjects"
          value={String(subjects.length)}
          tone="blue"
        />
      </div>
      {(actionError || subjectError) && (
        <ErrorBox error={actionError || subjectError || ""} />
      )}
      {canManageSources && (
        <div className="academic-create-grid subject-create-grid">
          <Card>
            <h2>Add subject</h2>
            <p className="api-note">
              Notebook sources and tests are organized under each subject.
            </p>
            <form className="form-stack compact-form" onSubmit={createSubject}>
              <label>
                Subject name
                <input
                  required
                  minLength={1}
                  maxLength={128}
                  value={newSubject}
                  onChange={(e) => setNewSubject(e.target.value)}
                  placeholder="Accounting"
                />
              </label>
              <label>
                Subject code (optional)
                <input
                  maxLength={32}
                  value={subjectCode}
                  onChange={(e) => setSubjectCode(e.target.value)}
                  placeholder="Generated if blank"
                />
              </label>
              <button
                className="button primary"
                disabled={busy === "subject"}
              >
                {busy === "subject" ? "Creating…" : "Add subject"}
              </button>
            </form>
          </Card>
        </div>
      )}
      {loading ? (
        <LoadingCard />
      ) : (
        <Card>
          <div className="card-heading">
            <div>
              <h2>Subjects</h2>
              <p>
                Subjects available for student access, notebook sources, and
                tests.
              </p>
            </div>
            <span className="count-badge">{subjects.length}</span>
          </div>
          {subjects.length ? (
            subjects.map((subject) => (
                <div key={getId(subject)} className="subject-source-group">
                  <div className="source-row">
                    <span className="row-icon">
                      <BookOpen size={17} />
                    </span>
                    <div>
                      <b>{getTitle(subject)}</b>
                      <small>{subject.code ?? ""}</small>
                    </div>
                    {canManageSources && editingSubjectId !== getId(subject) && (
                      <div className="button-row compact-actions">
                        <button className="button secondary small-button" type="button" onClick={() => {
                          setEditingSubjectId(getId(subject));
                          setEditingSubjectName(String(subject.name ?? ""));
                          setEditingSubjectCode(String(subject.code ?? ""));
                          setActionError("");
                        }}>Edit</button>
                        <button className="button danger small-button" type="button" disabled={busy !== ""} onClick={() => void deleteSubject(subject)}>Delete</button>
                      </div>
                    )}
                  </div>
                  {canManageSources && editingSubjectId === getId(subject) && (
                    <form className="inline-fields subject-edit-form" onSubmit={(event) => {
                      event.preventDefault();
                      void saveSubject(getId(subject));
                    }}>
                      <label>Subject name<input required minLength={1} maxLength={128} value={editingSubjectName} onChange={(event) => setEditingSubjectName(event.target.value)} /></label>
                      <label>Subject code<input required minLength={1} maxLength={32} value={editingSubjectCode} onChange={(event) => setEditingSubjectCode(event.target.value)} /></label>
                      <button className="button primary small-button" disabled={busy !== ""}>{busy === "edit" ? "Saving…" : "Save"}</button>
                      <button className="button secondary small-button" type="button" onClick={() => setEditingSubjectId("")}>Cancel</button>
                    </form>
                  )}
                  {canManageSources && (
                    <SubjectSources subjectId={getId(subject)} canManage />
                  )}
                </div>
              ))
          ) : (
            <Empty
              title="No subjects yet"
              detail="Add a subject above to get started."
              icon={BookOpen}
            />
          )}
        </Card>
      )}
    </>
  );
}
function SubjectSources({
  subjectId,
  canManage,
}: {
  subjectId: string;
  canManage: boolean;
}) {
  const { data, error, loading, refresh } = useResource<unknown[]>(
    `/ai-notebook/subjects/${subjectId}/sources`,
  );
  const sources = asArray<Data>(data);
  const [adding, setAdding] = useState(false);
  const [busy, setBusy] = useState(false);
  const [uploadError, setUploadError] = useState("");
  const refreshRef = useRef(refresh);
  refreshRef.current = refresh;
  const indexing = sources.some((source) => source.status === "processing");
  useEffect(() => {
    if (!canManage || !indexing) return;
    let active = true;
    let timeout: number | undefined;
    let attempt = 0;
    const delays = [5000, 10000, 15000, 30000];
    const poll = () => {
      if (!active) return;
      refreshRef.current();
      const delay = delays[Math.min(attempt, delays.length - 1)];
      attempt += 1;
      timeout = window.setTimeout(poll, delay);
    };
    timeout = window.setTimeout(poll, delays[0]);
    return () => {
      active = false;
      if (timeout !== undefined) window.clearTimeout(timeout);
    };
  }, [canManage, indexing]);

  async function upload(e: FormEvent) {
    e.preventDefault();
    const formElement = e.currentTarget as HTMLFormElement;
    const input = formElement.elements.namedItem(
      "subjectSource",
    ) as HTMLInputElement;
    const file = input.files?.[0];
    if (!file) return;
    setBusy(true);
    setUploadError("");
    const form = new FormData();
    form.append("file", file);
    form.append("subjectId", subjectId);
    try {
      await api("/ai-notebook/sources", { method: "POST", body: form });
      input.value = "";
      setAdding(false);
      refresh();
    } catch (ex) {
      setUploadError(
        ex instanceof Error ? ex.message : "Could not upload source.",
      );
    } finally {
      setBusy(false);
    }
  }

  async function remove(sourceId: string) {
    if (
      !window.confirm(
        "Delete this source from the subject and remove it from AI test-checking references?",
      )
    )
      return;
    setUploadError("");
    try {
      await api(`/ai-notebook/sources/${sourceId}`, { method: "DELETE" });
      refresh();
    } catch (ex) {
      setUploadError(
        ex instanceof Error ? ex.message : "Could not delete source.",
      );
    }
  }

  return (
    <div className="subject-sources">
      <div className="card-heading">
        <div>
          <h3>Subject sources</h3>
          <p>Shared with AI Notebook and AI test checking for this subject.</p>
        </div>
        {canManage && (
          <button
            className="button secondary small-button"
            onClick={() => setAdding((value) => !value)}
          >
            <Plus size={15} /> Add source
          </button>
        )}
      </div>
      {adding && canManage && (
        <form className="form-stack compact-form" onSubmit={upload}>
          <label>
            Source file (PDF, DOCX, or TXT)
            <input
              type="file"
              name="subjectSource"
              accept=".pdf,.docx,.txt"
              required
            />
          </label>
          <div className="button-row">
            <button className="button primary" disabled={busy}>
              {busy ? "Uploading…" : "Upload to subject"}
            </button>
            <button
              type="button"
              className="button secondary"
              disabled={busy}
              onClick={() => setAdding(false)}
            >
              Cancel
            </button>
          </div>
        </form>
      )}
      {uploadError && <ErrorBox error={uploadError} />}
      {error ? (
        <ErrorBox error={error} />
      ) : loading ? (
        <p className="api-note">Loading sources…</p>
      ) : sources.length ? (
        sources.map((source) => (
          <div className="source-row" key={getId(source)}>
            <span className="row-icon">
              <FileText size={16} />
            </span>
            <div>
              <b>{source.title ?? source.originalName}</b>
              <small>
                {source.status === "failed"
                  ? `Indexing failed: ${source.errorMessage ?? "Check the cloud embedding configuration."}`
                  : source.status === "processing"
                    ? `Indexing ${source.completedChunks ?? 0}/${source.totalChunks ?? 0} chunks`
                    : source.status === "ready"
                      ? "Ready · available to Notebook and test grading"
                      : source.status}
              </small>
            </div>
            {canManage && (
              <button
                className="button secondary small-button"
                onClick={() => void remove(getId(source))}
              >
                Remove
              </button>
            )}
          </div>
        ))
      ) : (
        <p className="api-note">
          No sources yet. Add a source to power this subject’s Notebook and AI
          test checking.
        </p>
      )}
    </div>
  );
}
function Team() {
  const { data, error, loading, refresh } =
    useResource<unknown[]>("/team-members");
  const { data: subjectData, error: subjectError } =
    useResource<unknown[]>("/subjects/list");
  const rows = asArray<Data>(data);
  const subjects = asArray<Data>(subjectData);
  const visiblePermissions = (value: unknown) =>
    asArray<string>(value).filter((permission) =>
      adminPermissionOptions.some(([option]) => option === permission),
    );
  const [creating, setCreating] = useState(false),
    [editing, setEditing] = useState(""),
    [memberDrafts, setMemberDrafts] = useState<Record<string, {
      fullName: string;
      email: string;
      phone: string;
      permissions: string[];
      subjectIds: string[];
    }>>({}),
    [email, setEmail] = useState(""),
    [fullName, setFullName] = useState(""),
    [password, setPassword] = useState(""),
    [permissions, setPermissions] = useState<string[]>(["teacher_assistant"]),
    [createSubjectIds, setCreateSubjectIds] = useState<string[]>([]),
    [errorMessage, setErrorMessage] = useState(""),
    [busy, setBusy] = useState(false),
    [memberBusy, setMemberBusy] = useState("");
  async function create(e: FormEvent) {
    e.preventDefault();
    if (
      permissions.includes("admin") &&
      !window.confirm("Grant this account full super-admin access?")
    )
      return;
    setErrorMessage("");
    setBusy(true);
    try {
      await api(
        "/team-members",
        json("POST", { email, password, fullName, permissions, subjectIds: createSubjectIds }),
      );
      setCreating(false);
      setEmail("");
      setFullName("");
      setPassword("");
      setPermissions(["teacher_assistant"]);
      setCreateSubjectIds([]);
      refresh();
    } catch (ex) {
      setErrorMessage(
        ex instanceof Error ? ex.message : "Could not create team member.",
      );
    } finally {
      setBusy(false);
    }
  }
  function startEditing(member: Data) {
    const userId = getId(member);
    setEditing(userId);
    setMemberDrafts((current) => ({
      ...current,
      [userId]: {
        fullName: String(member.fullName ?? ""),
        email: String(member.email ?? ""),
        phone: String(member.phone ?? ""),
        permissions: visiblePermissions(member.permissions ?? member.adminPermissions),
        subjectIds: asArray<string>(member.subjectIds),
      },
    }));
  }
  function updateMemberDraft(
    userId: string,
    change: Partial<NonNullable<typeof memberDrafts[string]>>,
  ) {
    setMemberDrafts((current) => ({
      ...current,
      [userId]: { ...current[userId], ...change },
    }));
  }
  async function saveMember(userId: string) {
    const draft = memberDrafts[userId];
    if (!draft) return;
    if (
      draft.permissions.includes("admin") &&
      !window.confirm("Grant this account full super-admin access?")
    )
      return;
    setErrorMessage("");
    setMemberBusy(userId);
    try {
      await api(
        `/team-members/${userId}`,
        json("PUT", draft),
      );
      setEditing("");
      refresh();
    } catch (ex) {
      setErrorMessage(ex instanceof Error ? ex.message : "Could not update team member.");
    } finally {
      setMemberBusy("");
    }
  }
  async function deleteMember(member: Data) {
    const userId = getId(member);
    const name = String(member.fullName || member.email || "this team member");
    if (!window.confirm(`Permanently delete ${name}'s team account? This cannot be undone.`)) return;
    setErrorMessage("");
    setMemberBusy(userId);
    try {
      await api(`/team-members/${userId}`, { method: "DELETE" });
      if (editing === userId) setEditing("");
      refresh();
    } catch (ex) {
      setErrorMessage(ex instanceof Error ? ex.message : "Could not delete team member.");
    } finally {
      setMemberBusy("");
    }
  }
  return (
    <>
      <Heading
        eyebrow="PEOPLE & PERMISSIONS"
        title="Team Members"
        description="Administrative access is governed by server-side permissions."
        action={
          <button
            className="button secondary"
            onClick={() => setCreating((value) => !value)}
          >
            <Plus size={16} /> Add member
          </button>
        }
      />
      {errorMessage && <ErrorBox error={errorMessage} />}
      {creating && (
        <Card>
          <div className="card-heading">
            <div>
              <h2>Add team member</h2>
              <p>Create an administrator account and choose its permissions.</p>
            </div>
          </div>
          <form className="form-stack" onSubmit={create}>
            <div className="inline-fields">
              <label>
                Full name
                <input
                  maxLength={200}
                  value={fullName}
                  onChange={(e) => setFullName(e.target.value)}
                />
              </label>
              <label>
                Email
                <input
                  type="email"
                  required
                  autoComplete="email"
                  value={email}
                  onChange={(e) => setEmail(e.target.value)}
                />
              </label>
            </div>
            <label>
              Initial password
              <input
                type="password"
                required
                minLength={9}
                autoComplete="new-password"
                value={password}
                onChange={(e) => setPassword(e.target.value)}
              />
            </label>
            <fieldset className="permission-options">
              <legend>Permissions</legend>
              {adminPermissionOptions.map(([value, title]) => (
                <label key={value}>
                  <input
                    type="checkbox"
                    checked={permissions.includes(value)}
                    onChange={(e) =>
                      setPermissions(
                        e.target.checked
                          ? [...permissions, value]
                          : permissions.filter((entry) => entry !== value),
                      )
                    }
                  />
                  {title}
                </label>
              ))}
            </fieldset>
            <fieldset className="permission-options compact-permissions subject-scope-options">
              <legend>Subject access scope</legend>
              <p className="api-note subject-scope-help">Choose one or more subjects to limit this member. Leave all unchecked for unrestricted access.</p>
              {subjectError ? <ErrorBox error={subjectError} /> : subjects.length ? subjects.map((subject) => {
                const subjectId = getId(subject);
                return <label key={subjectId}><input type="checkbox" checked={createSubjectIds.includes(subjectId)} onChange={(event) => setCreateSubjectIds((current) => event.target.checked ? [...current, subjectId] : current.filter((value) => value !== subjectId))} /><span>{getTitle(subject)}</span></label>;
              }) : <p className="subtle-padding">No subjects are available.</p>}
            </fieldset>
            <button
              className="button primary"
              disabled={busy || permissions.length === 0}
            >
              {busy ? "Creating…" : "Create member"}
            </button>
          </form>
        </Card>
      )}
      <Card>
        {loading ? (
          <LoadingCard />
        ) : error ? (
          <ErrorBox error={error} />
        ) : rows.length ? (
          <div className="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>Team member</th>
                  <th>Email</th>
                  <th>Permissions</th>
                  <th>Subject access</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                {rows.map((r, i) => (
                  <Fragment key={getId(r) || i}>
                  <tr>
                    <td>
                      <b>{r.fullName ?? "—"}</b>
                    </td>
                    <td>{r.email ?? "—"}</td>
                    <td>
                      <div className="tag-list">
                        {visiblePermissions(r.permissions ?? r.adminPermissions).map((p) => (
                          <span className="permission-tag" key={p}>{p.replaceAll("_", " ")}</span>
                        ))}
                      </div>
                    </td>
                    <td>
                      {asArray<string>(r.subjectIds).length
                        ? asArray<string>(r.subjectIds).map((subjectId) => (
                            <span className="permission-tag" key={subjectId}>
                              {getTitle(subjects.find((subject) => getId(subject) === subjectId) ?? { id: subjectId })}
                            </span>
                          ))
                        : <span className="api-note">Unrestricted</span>}
                    </td>
                    <td>
                      <div className="button-row compact-actions">
                        <button
                          className="button secondary small-button"
                          type="button"
                          onClick={() => editing === getId(r) ? setEditing("") : startEditing(r)}
                        >
                          <Pencil size={14} /> {editing === getId(r) ? "Close" : "Edit"}
                        </button>
                        <button
                          className="button secondary small-button danger-button"
                          type="button"
                          disabled={memberBusy === getId(r)}
                          onClick={() => void deleteMember(r)}
                        >
                          <Trash2 size={14} /> Delete
                        </button>
                      </div>
                    </td>
                  </tr>
                  {editing === getId(r) && memberDrafts[getId(r)] && (
                    <tr>
                      <td colSpan={5}>
                        <form
                          className="team-edit-panel"
                          onSubmit={(event) => {
                            event.preventDefault();
                            void saveMember(getId(r));
                          }}
                        >
                          <div className="inline-fields">
                            <label>Full name<input maxLength={200} value={memberDrafts[getId(r)].fullName} onChange={(event) => updateMemberDraft(getId(r), { fullName: event.target.value })} /></label>
                            <label>Email<input type="email" required maxLength={254} value={memberDrafts[getId(r)].email} onChange={(event) => updateMemberDraft(getId(r), { email: event.target.value })} /></label>
                            <label>Phone<input maxLength={32} value={memberDrafts[getId(r)].phone} onChange={(event) => updateMemberDraft(getId(r), { phone: event.target.value })} /></label>
                          </div>
                          <fieldset className="permission-options">
                            <legend>Permissions</legend>
                            {adminPermissionOptions.map(([value, title]) => (
                              <label key={value}>
                                <input
                                  type="checkbox"
                                  checked={memberDrafts[getId(r)].permissions.includes(value)}
                                  onChange={(event) => {
                                    const current = memberDrafts[getId(r)].permissions;
                                    updateMemberDraft(getId(r), {
                                      permissions: event.target.checked
                                        ? [...new Set([...current, value])]
                                        : current.filter((permission) => permission !== value),
                                    });
                                  }}
                                />
                                {title}
                              </label>
                            ))}
                          </fieldset>
                          <fieldset className="permission-options compact-permissions subject-scope-options">
                            <legend>Subject access scope</legend>
                            <p className="api-note subject-scope-help">Select one or more subjects to restrict access. No selections means unrestricted access.</p>
                            {subjectError ? <ErrorBox error={subjectError} /> : subjects.length ? subjects.map((subject) => {
                              const subjectId = getId(subject);
                              return (
                                <label key={subjectId}>
                                  <input
                                    type="checkbox"
                                    checked={memberDrafts[getId(r)].subjectIds.includes(subjectId)}
                                    onChange={(event) => {
                                      const current = memberDrafts[getId(r)].subjectIds;
                                      updateMemberDraft(getId(r), {
                                        subjectIds: event.target.checked
                                          ? [...new Set([...current, subjectId])]
                                          : current.filter((id) => id !== subjectId),
                                      });
                                    }}
                                  />
                                  <span>{getTitle(subject)}</span>
                                </label>
                              );
                            }) : <p className="subtle-padding">No subjects are available.</p>}
                          </fieldset>
                          <div className="button-row">
                            <button className="button primary" disabled={memberBusy === getId(r) || memberDrafts[getId(r)].permissions.length === 0}>
                              {memberBusy === getId(r) ? "Saving…" : "Save changes"}
                            </button>
                            <button className="button secondary" type="button" disabled={memberBusy === getId(r)} onClick={() => setEditing("")}>
                              Cancel
                            </button>
                          </div>
                        </form>
                      </td>
                    </tr>
                  )}
                  </Fragment>
                ))}
              </tbody>
            </table>
          </div>
        ) : (
          <Empty
            title="No team members"
            detail="No team members were returned for your account."
            icon={Users}
          />
        )}
      </Card>
    </>
  );
}
function Students() {
  const { data: userData, error, loading, refresh } = useResource<unknown[]>("/users");
  const { data: subjectData, error: subjectError } = useResource<unknown[]>("/subjects/list");
  const students = asArray<Data>(userData).filter((user) => user.role === "student");
  const subjects = asArray<Data>(subjectData);
  const [search, setSearch] = useState("");
  const [creating, setCreating] = useState(false);
  const [fullName, setFullName] = useState("");
  const [email, setEmail] = useState("");
  const [phone, setPhone] = useState("");
  const [crn, setCrn] = useState("");
  const [subjectIds, setSubjectIds] = useState<string[]>([]);
  const [busy, setBusy] = useState(false);
  const [formError, setFormError] = useState("");
  const [notice, setNotice] = useState("");
  const filtered = students.filter((student) =>
    `${student.fullName ?? ""} ${student.email ?? ""} ${student.studentId ?? ""} ${student.phone ?? ""}`
      .toLowerCase()
      .includes(search.toLowerCase()),
  );

  async function createStudent(event: FormEvent) {
    event.preventDefault();
    setBusy(true);
    setFormError("");
    setNotice("");
    try {
      const created = await api<Data>("/users", json("POST", {
        role: "student",
        fullName: fullName.trim(),
        email: email.trim(),
        phone: phone.trim(),
        crn: crn.trim(),
        subjectIds,
      }));
      setFullName("");
      setEmail("");
      setPhone("");
      setCrn("");
      setSubjectIds([]);
      setCreating(false);
      setNotice(`Student created. CRN: ${created.studentId}. Initial password: abc@123. The student must change it at first sign-in.`);
      await refresh();
    } catch (ex) {
      setFormError(ex instanceof Error ? ex.message : "Could not create student account.");
    } finally {
      setBusy(false);
    }
  }

  return (
    <>
      <Heading
        eyebrow="STUDENT ACCOUNTS"
        title="Students"
        description="Create student logins and choose which subjects they can access."
        action={<button className="button primary" onClick={() => setCreating((value) => !value)}><Plus size={16} /> Add student</button>}
      />
      {error && <ErrorBox error={error} />}
      {subjectError && <ErrorBox error={subjectError} />}
      {formError && <ErrorBox error={formError} />}
      {notice && <div className="alert success" role="status">{notice}</div>}
      {creating && (
        <Card>
          <div className="card-heading"><div><h2>Add student</h2><p>The student signs in with this email and CRN.</p></div></div>
          <form className="form-stack" onSubmit={(event) => void createStudent(event)}>
            <div className="inline-fields">
              <label>Full name<input name="fullName" autoComplete="name" required minLength={2} maxLength={200} value={fullName} onChange={(event) => setFullName(event.target.value)} /></label>
              <label>CRN number<input name="crn" required maxLength={32} value={crn} onChange={(event) => setCrn(event.target.value)} /></label>
            </div>
            <div className="inline-fields">
              <label>Student email<input name="email" type="email" autoComplete="email" required maxLength={254} value={email} onChange={(event) => setEmail(event.target.value)} /></label>
              <label>Phone number<input name="phone" type="tel" autoComplete="tel" required minLength={7} maxLength={32} value={phone} onChange={(event) => setPhone(event.target.value)} /></label>
            </div>
            <fieldset className="permission-options">
              <legend>Subject access</legend>
              {subjects.length ? subjects.map((subject) => {
                const id = getId(subject);
                return <label key={id}><input type="checkbox" checked={subjectIds.includes(id)} onChange={(event) => setSubjectIds((current) => event.target.checked ? [...current, id] : current.filter((value) => value !== id))} />{getTitle(subject)}</label>;
              }) : <p className="subtle-padding">Create a subject first, or add access later from Users.</p>}
            </fieldset>
            <p className="subtle-padding">Initial password: <b>abc@123</b>. The student must change it at first sign-in.</p>
            <button className="button primary" disabled={busy}>{busy ? "Creating…" : "Create student"}</button>
          </form>
        </Card>
      )}
      <Card>
        <div className="card-heading"><div><h2>Student accounts</h2><p>{students.length} students</p></div></div>
        <label className="search-field"><Search size={16} /><input value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Search name, email, CRN or phone" aria-label="Search students" /></label>
        {loading ? <LoadingCard /> : filtered.length ? filtered.map((student) => (
          <div className="source-row" key={getId(student)}>
            <span className="row-icon"><Users size={16} /></span>
            <div><b>{student.fullName || student.email || getId(student)}</b><small>{student.email} · CRN {student.studentId || "—"} · {student.phone || "No phone"}</small></div>
          </div>
        )) : <Empty title="No students found" detail="Create a student account or adjust the search." icon={Users} />}
      </Card>
    </>
  );
}
function UsersAdmin() {
  const { data: userData, error, loading, refresh } = useResource<unknown[]>("/users");
  const { data: subjectData, error: subjectError } = useResource<unknown[]>("/subjects/list");
  const students = asArray<Data>(userData).filter((user) => user.role === "student");
  const subjects = asArray<Data>(subjectData);
  const [search, setSearch] = useState("");
  const [editingId, setEditingId] = useState("");
  const [draftSubjectIds, setDraftSubjectIds] = useState<string[]>([]);
  const [savingId, setSavingId] = useState("");
  const [errorMessage, setErrorMessage] = useState("");
  const [notice, setNotice] = useState("");
  const filtered = students.filter((student) =>
    `${student.fullName ?? ""} ${student.email ?? ""} ${student.studentId ?? ""}`
      .toLowerCase()
      .includes(search.toLowerCase()),
  );

  async function saveAccess(userId: string) {
    setSavingId(userId);
    setErrorMessage("");
    setNotice("");
    try {
      await api(`/users/${userId}/subjects`, json("PUT", { subjectIds: draftSubjectIds }));
      setEditingId("");
      setNotice("Student subject access updated.");
      await refresh();
    } catch (ex) {
      setErrorMessage(ex instanceof Error ? ex.message : "Could not update subject access.");
    } finally {
      setSavingId("");
    }
  }

  return (
    <>
      <Heading eyebrow="STUDENT ACCESS" title="Users" description="Manage the subjects each student can open in Tests and AI Notebook." />
      {error && <ErrorBox error={error} />}
      {subjectError && <ErrorBox error={subjectError} />}
      {errorMessage && <ErrorBox error={errorMessage} />}
      {notice && <div className="alert success" role="status">{notice}</div>}
      <Card>
        <div className="card-heading"><div><h2>Student subject access</h2><p>{students.length} students</p></div></div>
        <label className="search-field"><Search size={16} /><input value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Search name, email or CRN" aria-label="Search students" /></label>
        {loading ? <LoadingCard /> : filtered.length ? filtered.map((student) => {
          const userId = getId(student);
          const currentSubjectIds = asArray<string>(student.subjectIds);
          const isEditing = editingId === userId;
          return (
            <div className="subject-source-group" key={userId}>
              <div className="source-row">
                <span className="row-icon"><Users size={16} /></span>
                <div><b>{student.fullName || student.email || userId}</b><small>{student.email} · CRN {student.studentId || "—"} · {currentSubjectIds.length} subjects</small></div>
                <button className="button secondary" onClick={() => { setEditingId(isEditing ? "" : userId); setDraftSubjectIds(currentSubjectIds); setErrorMessage(""); }}>{isEditing ? "Cancel" : "Manage access"}</button>
              </div>
              {isEditing && <div className="detail-card">
                <fieldset className="permission-options compact-permissions"><legend>Subjects this student can access</legend>
                  {subjects.length ? subjects.map((subject) => {
                    const subjectId = getId(subject);
                    return <label key={subjectId}><input type="checkbox" checked={draftSubjectIds.includes(subjectId)} onChange={(event) => setDraftSubjectIds((current) => event.target.checked ? [...current, subjectId] : current.filter((value) => value !== subjectId))} />{getTitle(subject)}</label>;
                  }) : <p className="subtle-padding">No subjects are available.</p>}
                </fieldset>
                <button className="button primary" disabled={savingId === userId} onClick={() => void saveAccess(userId)}>{savingId === userId ? "Saving…" : "Save access"}</button>
              </div>}
            </div>
          );
        }) : <Empty title="No students found" detail="Add a student from the Students tab or adjust the search." icon={Users} />}
      </Card>
    </>
  );
}function TestAdmin({
  user,
  notify,
  settingsOnly = false,
}: {
  user: Person;
  notify: (s: string) => void;
  settingsOnly?: boolean;
}) {
  const { data, error, loading, refresh: refreshTests } = useResource<unknown[]>(
    settingsOnly ? undefined : "/tests",
  );
  const { data: subjectData } = useResource<unknown[]>(
    settingsOnly ? undefined : "/subjects/list",
  );
  const canManageGradingSettings = Boolean(
    roleIsAdmin(user) &&
      user.adminPermissions?.includes("admin"),
  );
  const canManageTests = Boolean(
    user.adminPermissions?.some((permission) =>
      ["admin", "teacher_assistant"].includes(permission),
    ),
  );
  const {
    data: gradingSettingsData,
    error: gradingSettingsError,
    loading: gradingSettingsLoading,
    refresh: refreshGradingSettings,
  } = useResource<Data>(
    canManageGradingSettings ? "/tests/ai-grading-settings" : undefined,
  );
  const tests = asArray<Data>(data),
    subjects = asArray<Data>(subjectData);
  const [deficiencyReportPrompt, setDeficiencyReportPrompt] = useState("");
  const [resultReleaseDelayHours, setResultReleaseDelayHours] = useState("24");
  const [notebookDailyQuestionLimit, setNotebookDailyQuestionLimit] =
    useState("15");
  const [savingReportPrompt, setSavingReportPrompt] = useState(false);
  const [reportPromptMessage, setReportPromptMessage] = useState("");
  const [sourceFileBusy, setSourceFileBusy] = useState("");
  const [editingTitleTest, setEditingTitleTest] = useState<Data | null>(null);
  const [titleDraft, setTitleDraft] = useState("");
  const [lifecycleTest, setLifecycleTest] = useState<Data | null>(null);
  const [lifecycleBusy, setLifecycleBusy] = useState(false);
  const [selected, setSelected] = useState(""),
    [showCreate, setShowCreate] = useState(false),
    [showPreview, setShowPreview] = useState(false),
    [subjectId, setSubjectId] = useState("");
  const activeTab = settingsOnly ? "settings" : "tests";
  async function saveTestTitle(event: FormEvent) {
    event.preventDefault();
    if (!editingTitleTest) return;
    setLifecycleBusy(true);
    try {
      await api(`/tests/${getId(editingTitleTest)}`, json("PUT", { title: titleDraft }));
      setEditingTitleTest(null);
      await refreshTests();
      notify("Test title updated.");
    } catch (ex) {
      notify(ex instanceof Error ? ex.message : "Could not update the test title.");
    } finally {
      setLifecycleBusy(false);
    }
  }
  async function deleteTest(mode: "archive" | "permanent") {
    if (!lifecycleTest) return;
    setLifecycleBusy(true);
    try {
      await api(
        `/tests/${getId(lifecycleTest)}`,
        json("DELETE", { mode }),
      );
      if (selected === getId(lifecycleTest)) setSelected("");
      setLifecycleTest(null);
      await refreshTests();
      notify(
        mode === "archive"
          ? "Test removed from current and future access. Student history was preserved."
          : "Test, student reports, and related files were permanently deleted.",
      );
    } catch (ex) {
      notify(ex instanceof Error ? ex.message : "Could not delete the test.");
    } finally {
      setLifecycleBusy(false);
    }
  }
  useEffect(() => {
    if (gradingSettingsData) {
      setDeficiencyReportPrompt(
        String(gradingSettingsData.deficiencyReportPrompt ?? ""),
      );
      setResultReleaseDelayHours(
        String(gradingSettingsData.resultReleaseDelayHours ?? 24),
      );
      setNotebookDailyQuestionLimit(
        String(gradingSettingsData.notebookDailyQuestionLimit ?? 15),
      );
    }
  }, [gradingSettingsData]);
  async function saveDeficiencyReportPrompt(event: FormEvent) {
    event.preventDefault();
    setSavingReportPrompt(true);
    setReportPromptMessage("");
    try {
      await api(
        "/tests/ai-grading-settings",
        json("PUT", {
          deficiencyReportPrompt,
          resultReleaseDelayHours: Number(resultReleaseDelayHours),
          notebookDailyQuestionLimit: Number(notebookDailyQuestionLimit),
        }),
      );
      setReportPromptMessage("Application settings saved.");
      refreshGradingSettings();
    } catch (ex) {
      setReportPromptMessage(
        ex instanceof Error
          ? ex.message
          : "Could not save application settings.",
      );
    } finally {
      setSavingReportPrompt(false);
    }
  }
  async function downloadTestSourceFile(testId: string, file: Data) {
    const fileId = getId(file);
    if (!fileId) return;
    setSourceFileBusy(fileId);
    try {
      const token = getToken();
      const response = await fetch(
        `${API_BASE}/api/tests/${testId}/source-files/${fileId}`,
        { headers: token ? { Authorization: `Bearer ${token}` } : {} },
      );
      if (!response.ok) {
        throw new Error(
          `Could not download the source file (${response.status}).`,
        );
      }
      const url = URL.createObjectURL(await response.blob());
      const link = document.createElement("a");
      link.href = url;
      link.download = String(file.originalName ?? "test-source");
      document.body.append(link);
      link.click();
      link.remove();
      window.setTimeout(() => URL.revokeObjectURL(url), 30_000);
    } catch (ex) {
      notify(
        ex instanceof Error
          ? ex.message
          : "Could not download the source file.",
      );
    } finally {
      setSourceFileBusy("");
    }
  }
  const filtered = tests.filter((t) => {
    if (subjectId && String(t.subjectId ?? t.subject?.id ?? "") !== subjectId)
      return false;
    return true;
  });
  return (
    <>
      <Heading
        eyebrow={settingsOnly ? "ADMINISTRATION" : "ASSESSMENTS"}
        title={settingsOnly ? "Application Settings" : "Tests & Marks"}
        description={settingsOnly
          ? "Manage shared application behavior and grading settings."
          : "Browse tests by subject, review attempts, and update marks."}
        action={!settingsOnly && activeTab === "tests" && canManageTests ? (
          <div className="button-row">
            <button
              className="button secondary"
              onClick={() => setShowPreview(true)}
            >
              Preview questions
            </button>
            <button
              className="button primary"
              onClick={() => setShowCreate(true)}
            >
              <Plus size={16} /> Create test
            </button>
          </div>
        ) : undefined}
      />
      {activeTab === "settings" && canManageGradingSettings ? (
      <Card className="grading-prompt-card">
        <div className="card-heading">
          <div>
            <h2>Application settings</h2>
            <p>
              Configure the student AI Notebook limit and test result release.
            </p>
          </div>
          <Sparkles size={18} />
        </div>
        {gradingSettingsError ? (
          <ErrorBox error={gradingSettingsError} />
        ) : (
          <form
            className="form-stack compact-form"
            onSubmit={saveDeficiencyReportPrompt}
          >
            <label htmlFor="notebook-daily-question-limit">
              AI Notebook questions per student per day
              <input
                id="notebook-daily-question-limit"
                type="number"
                min="1"
                max="1000"
                step="1"
                required
                value={notebookDailyQuestionLimit}
                onChange={(event) =>
                  setNotebookDailyQuestionLimit(event.target.value)
                }
                disabled={gradingSettingsLoading || savingReportPrompt}
                aria-describedby="notebook-daily-question-limit-help"
              />
              <small id="notebook-daily-question-limit-help">
                Default is 15. Failed answers are not counted. The daily
                allowance resets at midnight Asia/Karachi time.
              </small>
            </label>
            <label htmlFor="ai-deficiency-report-prompt">
              AI deficiency report instructions
              <textarea
                id="ai-deficiency-report-prompt"
                value={deficiencyReportPrompt}
                onChange={(event) =>
                  setDeficiencyReportPrompt(event.target.value)
                }
                placeholder="Example: Write concise, encouraging feedback. Mention the main strength first, then identify specific missing points and one practical next step. Use plain language suitable for students."
                maxLength={4000}
                rows={5}
                disabled={gradingSettingsLoading || savingReportPrompt}
                aria-describedby="ai-deficiency-report-prompt-help"
              />
            </label>
            <label htmlFor="test-result-release-delay">
              Release marks to students after (hours)
              <input
                id="test-result-release-delay"
                type="number"
                min="0"
                max="720"
                step="1"
                required
                value={resultReleaseDelayHours}
                onChange={(event) =>
                  setResultReleaseDelayHours(event.target.value)
                }
                disabled={gradingSettingsLoading || savingReportPrompt}
                aria-describedby="test-result-release-delay-help"
              />
              <small id="test-result-release-delay-help">
                AI marks are saved when assessment finishes but stay hidden
                until this delay passes. Use 0 to release marks immediately;
                the maximum is 720 hours.
              </small>
            </label>
            <div className="grading-prompt-footer">
              <small id="ai-deficiency-report-prompt-help">
                Up to 4,000 characters. This guides report wording only; it
                cannot change marks, answer matching, or grading rules. Leaving
                it empty uses the built-in report guidance.
              </small>
              <span>{deficiencyReportPrompt.length}/4000</span>
            </div>
            {reportPromptMessage && (
              <div
                className={`alert ${reportPromptMessage.includes("saved") ? "success" : "error"}`}
                role="status"
              >
                {reportPromptMessage}
              </div>
            )}
            <div className="button-row">
              <button
                className="button primary"
                disabled={gradingSettingsLoading || savingReportPrompt}
              >
                {savingReportPrompt ? "Saving…" : "Save settings"}
              </button>
              <button
                className="button secondary"
                type="button"
                disabled={
                  gradingSettingsLoading ||
                  savingReportPrompt ||
                  !deficiencyReportPrompt
                }
                onClick={() => {
                  setDeficiencyReportPrompt("");
                  setReportPromptMessage(
                    "Prompt cleared in the editor. Save to apply the built-in guidance.",
                  );
                }}
              >
                Clear field
              </button>
            </div>
          </form>
        )}
      </Card>
      ) : (
      <>
      {error && <ErrorBox error={error} />}
      <div className="scope-bar">
        <label>
          Subject
          <select
            value={subjectId}
            onChange={(e) => setSubjectId(e.target.value)}
          >
            <option value="">All subjects</option>
            {subjects.map((f) => (
              <option key={getId(f)} value={getId(f)}>
                {getTitle(f)}
              </option>
            ))}
          </select>
        </label>
        <span className="scope-count">{filtered.length} scoped tests</span>
      </div>
      <p className="test-admin-note">
        Expired-test requests are handled in Access Requests.
      </p>
      {loading ? (
        <LoadingCard />
      ) : (
        <Card>
          <div className="card-heading">
            <div>
              <h2>Tests</h2>
              <p>Open a test to review attempts and marks.</p>
            </div>
            <span className="count-badge">{filtered.length}</span>
          </div>
          {filtered.length ? (
            filtered.map((t, i) => (
              <div className="test-admin-row-group" key={getId(t) || i}>
                <div className="test-row">
                  <button
                    type="button"
                    className="test-row-open"
                    aria-expanded={selected === getId(t)}
                    aria-controls={`attempt-review-${getId(t)}`}
                    onClick={() =>
                      setSelected((current) =>
                        current === getId(t) ? "" : getId(t),
                      )
                    }
                  >
                    <span className="row-icon">
                      <FileText size={18} />
                    </span>
                    <span className="row-main">
                      <b>{getTitle(t)}</b>
                      <small>
                        {t.totalMarks ?? "?"} marks ·{" "}
                        {t.subject?.name ?? "Subject"}{" "}
                        · {formatDate(t.deadlineAt)}
                      </small>
                    </span>
                    <ChevronRight size={17} />
                  </button>
                  <button
                    type="button"
                    className="button secondary small-button"
                    aria-expanded={selected === getId(t)}
                    aria-controls={`attempt-review-${getId(t)}`}
                    onClick={() =>
                      setSelected((current) =>
                        current === getId(t) ? "" : getId(t),
                      )
                    }
                  >
                    <Clock3 size={14} /> Review attempts
                  </button>
                  <button
                    type="button"
                    className="button secondary small-button export-questions"
                    disabled={!asArray<Data>(t.questions).length}
                    aria-label={`Export questions for ${getTitle(t)}`}
                    title="Download all questions as a printable PDF question paper"
                    onClick={() => void exportTestQuestions(t)}
                  >
                    <Download size={14} /> Export PDF
                  </button>
                  {canManageTests && (
                    <>
                      <button
                        type="button"
                        className="button secondary small-button"
                        aria-label={`Edit title for ${getTitle(t)}`}
                        onClick={() => {
                          setEditingTitleTest(t);
                          setTitleDraft(getTitle(t));
                        }}
                      >
                        Edit title
                      </button>
                      <button
                        type="button"
                        className="button danger small-button"
                        aria-label={`Delete ${getTitle(t)}`}
                        onClick={() => setLifecycleTest(t)}
                      >
                        Delete
                      </button>
                    </>
                  )}
                  {t.archivedAt ? (
                    <span className="status archived-status">Archived</span>
                  ) : (
                    <span className="row-date">{t.status ?? "Published"}</span>
                  )}
                </div>
                {asArray<Data>(t.sourceFiles).length > 0 && (
                  <div className="source-file-list">
                    <b>Original source documents</b>
                    {asArray<Data>(t.sourceFiles).map((file) => (
                      <button
                        key={getId(file)}
                        className="button secondary small-button"
                        disabled={sourceFileBusy === getId(file)}
                        onClick={() =>
                          void downloadTestSourceFile(getId(t), file)
                        }
                      >
                        <Download size={14} />
                        {sourceFileBusy === getId(file)
                          ? "Downloading…"
                          : `Download ${file.originalName ?? "source file"}`}
                      </button>
                    ))}
                  </div>
                )}
                {selected === getId(t) && (
                  <AttemptReview
                    testId={selected}
                    onClose={() => setSelected("")}
                    notify={notify}
                  />
                )}
              </div>
            ))
          ) : (
            <Empty
              title={
                tests.length ? "No tests match this scope" : "No tests created"
              }
              detail="Select another subject or create a test for a subject."
              icon={FileText}
            />
          )}
        </Card>
      )}
      {showCreate && activeTab === "tests" && (
        <CreateTest onClose={() => setShowCreate(false)} notify={notify} />
      )}
      {showPreview && <QuestionPreview onClose={() => setShowPreview(false)} />}
      {editingTitleTest && (
        <div
          className="modal-backdrop"
          role="presentation"
          onClick={() => !lifecycleBusy && setEditingTitleTest(null)}
        >
          <section
            className="modal lifecycle-modal"
            role="dialog"
            aria-modal="true"
            aria-labelledby="edit-test-title-heading"
            onClick={(event) => event.stopPropagation()}
          >
            <div className="card-heading">
              <div>
                <div className="eyebrow">TEST DETAILS</div>
                <h2 id="edit-test-title-heading">Edit test title</h2>
              </div>
              <button
                type="button"
                className="icon-button"
                aria-label="Close"
                disabled={lifecycleBusy}
                onClick={() => setEditingTitleTest(null)}
              >
                <X />
              </button>
            </div>
            <form className="form-stack" onSubmit={(event) => void saveTestTitle(event)}>
              <label htmlFor="test-title-edit">Test title</label>
              <input
                id="test-title-edit"
                autoFocus
                required
                maxLength={200}
                value={titleDraft}
                onChange={(event) => setTitleDraft(event.target.value)}
                disabled={lifecycleBusy}
              />
              <div className="button-row">
                <button
                  type="button"
                  className="button secondary"
                  disabled={lifecycleBusy}
                  onClick={() => setEditingTitleTest(null)}
                >
                  Cancel
                </button>
                <button className="button primary" disabled={lifecycleBusy}>
                  {lifecycleBusy ? "Saving…" : "Save title"}
                </button>
              </div>
            </form>
          </section>
        </div>
      )}
      {lifecycleTest && (
        <div
          className="modal-backdrop"
          role="presentation"
          onClick={() => !lifecycleBusy && setLifecycleTest(null)}
        >
          <section
            className="modal lifecycle-modal"
            role="dialog"
            aria-modal="true"
            aria-labelledby="delete-test-heading"
            aria-describedby="delete-test-description"
            onClick={(event) => event.stopPropagation()}
          >
            <div className="card-heading">
              <div>
                <div className="eyebrow">TEST LIFECYCLE</div>
                <h2 id="delete-test-heading">Delete “{getTitle(lifecycleTest)}”?</h2>
              </div>
              <button
                type="button"
                className="icon-button"
                aria-label="Close"
                disabled={lifecycleBusy}
                onClick={() => setLifecycleTest(null)}
              >
                <X />
              </button>
            </div>
            <p id="delete-test-description">
              Choose whether to remove this test from student access while keeping
              reports, or permanently erase the test, all student attempt/report
              data, and related files.
            </p>
            <div className="button-row lifecycle-actions">
              <button
                type="button"
                className="button secondary"
                disabled={lifecycleBusy}
                onClick={() => setLifecycleTest(null)}
              >
                Cancel
              </button>
              <button
                type="button"
                className="button secondary"
                disabled={lifecycleBusy}
                onClick={() => void deleteTest("archive")}
              >
                {lifecycleBusy ? "Working…" : "Delete onwards"}
              </button>
              <button
                type="button"
                className="button danger"
                disabled={lifecycleBusy}
                onClick={() => void deleteTest("permanent")}
              >
                Permanently delete
              </button>
            </div>
          </section>
        </div>
      )}
      </>
      )}
    </>
  );
}
async function exportTestQuestions(test: Data) {
  const questions = asArray<Data>(test.questions)
    .slice()
    .sort(
      (left, right) =>
        Number(left.questionNumber ?? 0) - Number(right.questionNumber ?? 0),
    );
  if (!questions.length) return;
  const { jsPDF } = await import("jspdf");
  const pdf = new jsPDF({ format: "a4", unit: "mm" });
  const pageWidth = pdf.internal.pageSize.getWidth();
  const pageHeight = pdf.internal.pageSize.getHeight();
  const margin = 18;
  const contentBottom = pageHeight - 25;
  let y = 22;
  pdf.setProperties({ title: `${getTitle(test)} — Question Paper` });
  pdf.setFont("helvetica", "bold");
  pdf.setFontSize(18);
  pdf.text(getTitle(test), pageWidth / 2, y, { align: "center" });
  y += 9;
  pdf.setFont("helvetica", "normal");
  pdf.setFontSize(11);
  pdf.text("Question Paper", pageWidth / 2, y, { align: "center" });
  y += 10;
  pdf.setFontSize(10);
  pdf.text(`Total marks: ${test.totalMarks ?? "—"}`, margin, y);
  pdf.text("Name: ______________________________", margin + 55, y);
  y += 7;
  pdf.text("CRN: _______________________________", margin, y);
  pdf.text("Date: ______________________________", margin + 90, y);
  y += 7;
  pdf.setDrawColor(205, 215, 228);
  pdf.line(margin, y, pageWidth - margin, y);
  y += 10;

  const addPage = () => {
    pdf.addPage();
    y = 20;
  };
  questions.forEach((question, index) => {
    const questionNumber = question.questionNumber ?? index + 1;
    if (y + 14 > contentBottom) addPage();
    pdf.setFont("helvetica", "bold");
    pdf.setFontSize(11);
    pdf.text(`Question ${questionNumber}`, margin, y);
    pdf.text(`${question.marks ?? "—"} marks`, pageWidth - margin, y, {
      align: "right",
    });
    y += 6;
    pdf.setFont("helvetica", "normal");
    pdf.setFontSize(11);
    y = addPdfFormattedContent(
      pdf,
      questionTextForPdf(String(question.questionText ?? "").trim()) ||
        "(Question text unavailable)",
      {
        x: margin,
        width: pageWidth - margin * 2,
        y,
        bottom: contentBottom,
        addPage,
        fontSize: 11,
      },
    );
    y += 8;
  });
  const pageCount = pdf.getNumberOfPages();
  for (let page = 1; page <= pageCount; page += 1) {
    pdf.setPage(page);
    pdf.setFont("helvetica", "normal");
    pdf.setFontSize(8);
    pdf.setTextColor(120, 130, 145);
    pdf.text(
      `${getTitle(test)} · Page ${page} of ${pageCount}`,
      pageWidth / 2,
      pageHeight - 10,
      { align: "center" },
    );
  }
  const slug = getTitle(test)
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, "-")
    .replace(/^-|-$/g, "");
  pdf.save(`nexus-${slug || "test"}-question-paper.pdf`);
}
async function exportTestSolutions(testTitle: string, assessment: Data) {
  const questions = asArray<Data>(assessment?.questions)
    .filter((question) => String(question.solution ?? "").trim())
    .slice()
    .sort(
      (left, right) =>
        Number(left.questionNumber ?? 0) - Number(right.questionNumber ?? 0),
    );
  if (!questions.length) return;

  const { jsPDF } = await import("jspdf");
  const pdf = new jsPDF({ format: "a4", unit: "mm" });
  const pageWidth = pdf.internal.pageSize.getWidth();
  const pageHeight = pdf.internal.pageSize.getHeight();
  const margin = 18;
  const contentBottom = pageHeight - 20;
  let y = 22;
  pdf.setProperties({ title: `${testTitle} — Solutions` });
  pdf.setFont("helvetica", "bold");
  pdf.setFontSize(18);
  pdf.text(testTitle, pageWidth / 2, y, { align: "center" });
  y += 9;
  pdf.setFont("helvetica", "normal");
  pdf.setFontSize(11);
  pdf.text("Recognized Solutions", pageWidth / 2, y, { align: "center" });
  y += 13;

  const solutionOverview = [
    "| Question | Marks available |",
    "| --- | ---: |",
    ...questions.map(
      (question) =>
        `| ${question.questionNumber ?? "—"} | ${question.marksAvailable ?? "—"} |`,
    ),
  ].join("\n");
  y = addPdfFormattedContent(pdf, solutionOverview, {
    x: margin,
    width: pageWidth - margin * 2,
    y,
    bottom: contentBottom,
    addPage: () => pdf.addPage(),
    fontSize: 10,
  });
  y += 8;

  for (const question of questions) {
    if (y + 14 > contentBottom) {
      pdf.addPage();
      y = 20;
    }
    pdf.setFont("helvetica", "bold");
    pdf.setFontSize(11);
    pdf.text(
      `Question ${question.questionNumber} · ${question.marksAvailable} marks`,
      margin,
      y,
    );
    y += 7;
    pdf.setFont("helvetica", "normal");
    pdf.setFontSize(10);
    y = addPdfFormattedContent(pdf, String(question.solution), {
      x: margin,
      width: pageWidth - margin * 2,
      y,
      bottom: contentBottom,
      addPage: () => pdf.addPage(),
      fontSize: 10,
    });
    y += 8;
  }

  const pageCount = pdf.getNumberOfPages();
  for (let page = 1; page <= pageCount; page += 1) {
    pdf.setPage(page);
    pdf.setFont("helvetica", "normal");
    pdf.setFontSize(8);
    pdf.setTextColor(120, 130, 145);
    pdf.text(
      `${testTitle} · Solutions · Page ${page} of ${pageCount}`,
      pageWidth / 2,
      pageHeight - 10,
      { align: "center" },
    );
  }
  const slug = testTitle
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, "-")
    .replace(/^-|-$/g, "");
  pdf.save(`nexus-${slug || "test"}-solutions.pdf`);
}

async function exportDeficiencyReport(
  testTitle: string,
  assessment: Data,
  fallbackReport: string,
) {
  const { jsPDF } = await import("jspdf");
  const pdf = new jsPDF({ format: "a4", unit: "mm" });
  const pageWidth = pdf.internal.pageSize.getWidth();
  const pageHeight = pdf.internal.pageSize.getHeight();
  const margin = 18;
  const bottom = pageHeight - 18;
  let y = 20;
  const addPage = () => {
    pdf.addPage();
    y = 20;
  };
  const heading = (title: string, score?: string) => {
    if (y + 13 > bottom) addPage();
    pdf.setFillColor(238, 244, 252);
    pdf.roundedRect(margin, y - 5, pageWidth - margin * 2, 11, 2, 2, "F");
    pdf.setFont("helvetica", "bold");
    pdf.setFontSize(11);
    pdf.setTextColor(33, 55, 84);
    pdf.text(title, margin + 3, y + 2);
    if (score) {
      pdf.setTextColor(44, 93, 157);
      pdf.text(score, pageWidth - margin - 3, y + 2, { align: "right" });
    }
    y += 12;
  };

  pdf.setProperties({ title: `${testTitle} — Feedback report` });
  pdf.setFillColor(31, 65, 112);
  pdf.rect(0, 0, pageWidth, 33, "F");
  pdf.setFont("helvetica", "bold");
  pdf.setFontSize(17);
  pdf.setTextColor(255, 255, 255);
  pdf.text(testTitle, margin, 15);
  pdf.setFont("helvetica", "normal");
  pdf.setFontSize(10);
  pdf.text("Assessment feedback report", margin, 24);
  y = 43;

  const reportQuestions = asArray<Data>(assessment.questions);
  if (reportQuestions.length) {
    const awardedTotal = reportQuestions.reduce(
      (sum, question) => sum + Number(question.marksAwarded ?? 0),
      0,
    );
    const availableTotal = reportQuestions.reduce(
      (sum, question) => sum + Number(question.marksAvailable ?? 0),
      0,
    );
    const scoreSummary = [
      "| Question | Score | Result |",
      "| --- | ---: | ---: |",
      ...reportQuestions.map((question) => {
        const awarded = Number(question.marksAwarded ?? 0);
        const available = Number(question.marksAvailable ?? 0);
        const percentage =
          available > 0 ? `${((awarded / available) * 100).toFixed(1)}%` : "—";
        return `| ${question.questionNumber ?? "—"} | ${awarded} / ${available} | ${percentage} |`;
      }),
      `| Total | ${awardedTotal} / ${availableTotal} | ${
        availableTotal > 0
          ? `${((awardedTotal / availableTotal) * 100).toFixed(1)}%`
          : "—"
      } |`,
    ].join("\n");
    heading("Score summary");
    y = addPdfFormattedContent(pdf, scoreSummary, {
      x: margin + 2,
      width: pageWidth - margin * 2 - 4,
      y,
      bottom,
      addPage,
      fontSize: 9,
    });
    y += 7;
  }

  if (assessment.deficiencyReport || fallbackReport) {
    heading("Overall feedback");
    pdf.setFont("helvetica", "normal");
    pdf.setTextColor(45, 57, 74);
    pdf.setFontSize(10);
    y = addPdfFormattedContent(
      pdf,
      String(assessment.deficiencyReport ?? fallbackReport),
      { x: margin + 2, width: pageWidth - margin * 2 - 4, y, bottom, addPage },
    );
    y += 7;
  }

  for (const question of reportQuestions) {
    heading(
      `Question ${question.questionNumber}`,
      `${question.marksAwarded ?? 0} / ${question.marksAvailable ?? 0} marks`,
    );
    pdf.setFont("helvetica", "normal");
    pdf.setTextColor(45, 57, 74);
    pdf.setFontSize(10);
    if (question.deficiencyReport) {
      y = addPdfFormattedContent(pdf, String(question.deficiencyReport), {
        x: margin + 2,
        width: pageWidth - margin * 2 - 4,
        y,
        bottom,
        addPage,
      });
      y += 4;
    }
    for (const criterion of asArray<Data>(question.criterionResults)) {
      if (y + 8 > bottom) addPage();
      pdf.setFont("helvetica", "bold");
      pdf.setTextColor(38, 68, 108);
      pdf.setFontSize(9);
      pdf.text(
        `${String(criterion.criterion ?? "Criterion")} · ${criterion.marksAwarded ?? 0}/${criterion.marksAvailable ?? 0}`,
        margin + 2,
        y,
      );
      y += 5;
      pdf.setFont("helvetica", "normal");
      pdf.setTextColor(55, 67, 83);
      y = addPdfFormattedContent(pdf, String(criterion.feedback ?? ""), {
        x: margin + 4,
        width: pageWidth - margin * 2 - 6,
        y,
        bottom,
        addPage,
        fontSize: 9,
      });
      const evidence = asArray<string>(criterion.evidence);
      if (evidence.length) {
        pdf.setFont("helvetica", "italic");
        pdf.setTextColor(72, 87, 105);
        pdf.setFontSize(8);
        y = addPdfFormattedContent(
          pdf,
          `**Evidence:** ${evidence.map((item) => `“${item}”`).join("; ")}`,
          {
            x: margin + 6,
            width: pageWidth - margin * 2 - 8,
            y,
            bottom,
            addPage,
            fontSize: 8,
          },
        );
      }
      y += 3;
    }
    if (question.solution) {
      if (y + 10 > bottom) addPage();
      heading("Recognized solution");
      y = addPdfFormattedContent(pdf, String(question.solution), {
        x: margin + 2,
        width: pageWidth - margin * 2 - 4,
        y,
        bottom,
        addPage,
        fontSize: 9,
      });
      y += 6;
    }
    y += 3;
  }

  const pageCount = pdf.getNumberOfPages();
  for (let page = 1; page <= pageCount; page += 1) {
    pdf.setPage(page);
    pdf.setFont("helvetica", "normal");
    pdf.setFontSize(8);
    pdf.setTextColor(120, 130, 145);
    pdf.text(
      `Confidential assessment feedback · Page ${page} of ${pageCount}`,
      pageWidth / 2,
      pageHeight - 8,
      { align: "center" },
    );
  }
  const slug = testTitle
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, "-")
    .replace(/^-|-$/g, "");
  pdf.save(`nexus-${slug || "test"}-feedback-report.pdf`);
}

function QuestionPreview({ onClose }: { onClose: () => void }) {
  const [files, setFiles] = useState<File[]>([]),
    [result, setResult] = useState<Data | null>(null),
    [error, setError] = useState(""),
    [busy, setBusy] = useState(false);
  async function preview(e: FormEvent) {
    e.preventDefault();
    if (!files.length) return;
    setBusy(true);
    setError("");
    setResult(null);
    const form = new FormData();
    files.forEach((file) => form.append("files[]", file, file.name));
    try {
      setResult(
        await api<Data>("/tests/questions/preview", {
          method: "POST",
          body: form,
        }),
      );
    } catch (ex) {
      setError(ex instanceof Error ? ex.message : "Preview failed.");
    } finally {
      setBusy(false);
    }
  }
  return (
    <div className="modal-backdrop" role="presentation" onClick={onClose}>
      <section
        className="modal"
        role="dialog"
        aria-modal="true"
        aria-labelledby="preview-title"
        onClick={(e) => e.stopPropagation()}
      >
        <div className="card-heading">
          <div>
            <div className="eyebrow">QUESTION EXTRACTION</div>
            <h2 id="preview-title">Preview test questions</h2>
          </div>
          <button className="icon-button" onClick={onClose} aria-label="Close">
            <X />
          </button>
        </div>
        <p className="api-note">
          Preview only; this does not create or save a test.
        </p>
        <form className="form-stack" onSubmit={preview}>
          <label>
            Question, solution, or marking documents
            <input
              type="file"
              accept=".pdf,.docx"
              multiple
              required
              onChange={(e) => setFiles(Array.from(e.target.files ?? []))}
            />
          </label>
          {files.length > 0 && (
            <small>
              {files.length} selected:{" "}
              {files.map((file) => file.name).join(", ")}
            </small>
          )}
          <button className="button primary" disabled={!files.length || busy}>
            {busy ? "Analyzing?" : "Preview questions"}
            <ArrowRight size={16} />
          </button>
        </form>
        {error && <ErrorBox error={error} />}
        {result !== null && (
          <section className="question-preview-results" aria-live="polite">
            <div className="card-heading">
              <div>
                <h3>Extraction results</h3>
                <p>
                  {asArray<Data>(result.groups).length} question
                  {asArray<Data>(result.groups).length === 1 ? "" : "s"} found
                </p>
              </div>
            </div>
            {asArray<string>(result.errors).length > 0 && (
              <div className="preview-notices error">
                <b>Could not parse all content</b>
                <ul>
                  {asArray<string>(result.errors).map((item, index) => (
                    <li key={index}>{item}</li>
                  ))}
                </ul>
              </div>
            )}
            {asArray<string>(result.answerKeyWarnings).length > 0 && (
              <div className="preview-notices warning">
                <b>Check these answer-key calculations</b>
                <ul>
                  {asArray<string>(result.answerKeyWarnings).map(
                    (item, index) => (
                      <li key={index}>{item}</li>
                    ),
                  )}
                </ul>
              </div>
            )}
            {asArray<Data>(result.groups).map((group, index) => (
              <article
                className="question-preview-group"
                key={`${String(group.groupNumber ?? index + 1)}-${index}`}
              >
                <div className="question-preview-group-heading">
                  <h4>Question {String(group.groupNumber ?? index + 1)}</h4>
                  {group.difficulty && (
                    <span className="count-badge">
                      {String(group.difficulty)}
                    </span>
                  )}
                </div>
                <section className="question-preview-section">
                  <h5>Question</h5>
                  <FormattedContent
                    className="formatted-content"
                    content={group.questionText}
                  />
                </section>
                {group.solution && (
                  <details className="question-preview-section">
                    <summary>Solution</summary>
                    <FormattedContent
                      className="formatted-content"
                      content={group.solution}
                    />
                  </details>
                )}
                {asArray<Data>(group.markingScheme).length > 0 && (
                  <section className="question-preview-section">
                    <h5>Marking scheme</h5>
                    <ul>
                      {asArray<Data>(group.markingScheme).map(
                        (criterion, criterionIndex) => (
                          <li key={criterionIndex}>
                            {String(criterion.criterion ?? "")}
                            <b>{String(criterion.marks ?? 0)} marks</b>
                          </li>
                        ),
                      )}
                    </ul>
                  </section>
                )}
                {asArray<string>(group.errors).length > 0 && (
                  <div className="preview-notices error">
                    <b>Question needs attention</b>
                    <ul>
                      {asArray<string>(group.errors).map((item, errorIndex) => (
                        <li key={errorIndex}>{item}</li>
                      ))}
                    </ul>
                  </div>
                )}
              </article>
            ))}
          </section>
        )}
      </section>
    </div>
  );
}
function AccessRequests({ notify }: { notify: (s: string) => void }) {
  const { data, error, loading, refresh } = useResource<unknown[]>(
    "/tests/access-requests",
  );
  const requests = asArray<Data>(data);
  const [deadlines, setDeadlines] = useState<Record<string, string>>({});
  const [busyId, setBusyId] = useState("");

  async function grant(request: Data) {
    const attemptId = String(request.attemptId ?? "");
    const deadlineAt = deadlines[attemptId];
    if (!deadlineAt) return;
    setBusyId(attemptId);
    try {
      await api(
        `/tests/${request.testId}/deadline/${request.studentId}`,
        json("PUT", { deadlineAt: new Date(deadlineAt).toISOString() }),
      );
      await refresh();
      setDeadlines((current) => {
        const updated = { ...current };
        delete updated[attemptId];
        return updated;
      });
      notify("New test deadline granted.");
    } catch (e) {
      notify(e instanceof Error ? e.message : "Could not grant access.");
    } finally {
      setBusyId("");
    }
  }

  return (
    <>
      <Heading
        eyebrow="TEST ACCESS"
        title="Access Requests"
        description="Review expired-test requests and grant a new deadline when appropriate."
      />
      {error && <ErrorBox error={error} />}
      <Card>
        <div className="card-heading">
          <div>
            <h2>Pending requests</h2>
            <p>{requests.length} awaiting review</p>
          </div>
        </div>
        {loading ? (
          <LoadingCard />
        ) : requests.length ? (
          <div className="access-request-list">
            {requests.map((request) => {
              const attemptId = String(request.attemptId ?? "");
              const deadline = deadlines[attemptId] ?? "";
              const currentDeadline = request.testDeadlineAt
                ? new Date(request.testDeadlineAt)
                : null;
              const min = new Date(
                Math.max(Date.now(), currentDeadline?.getTime() ?? 0) + 60_000,
              );
              min.setMinutes(min.getMinutes() - min.getTimezoneOffset());
              return (
                <section className="access-request-row" key={attemptId}>
                  <div className="access-request-main">
                    <div className="row-icon">
                      <Clock3 size={18} />
                    </div>
                    <div>
                      <h3>{request.testTitle ?? "Test"}</h3>
                      <p>
                        {request.studentName ||
                          request.studentEmail ||
                          "Student"}
                        {request.studentEmail &&
                        request.studentName !== request.studentEmail
                          ? ` · ${request.studentEmail}`
                          : ""}
                      </p>
                      <small>
                        Requested {formatDate(request.requestedAt)} · Current
                        deadline {formatDate(request.testDeadlineAt)}
                      </small>
                    </div>
                    <span className="marks-chip">
                      {request.totalMarks ?? "—"} marks
                    </span>
                  </div>
                  <div className="access-request-actions">
                    <label>
                      New deadline
                      <input
                        type="datetime-local"
                        min={min.toISOString().slice(0, 16)}
                        value={deadline}
                        onChange={(event) =>
                          setDeadlines((current) => ({
                            ...current,
                            [attemptId]: event.target.value,
                          }))
                        }
                      />
                    </label>
                    <button
                      className="button primary"
                      disabled={
                        !deadline ||
                        Date.parse(deadline) <= Date.now() ||
                        busyId === attemptId
                      }
                      onClick={() => void grant(request)}
                    >
                      {busyId === attemptId
                        ? "Granting…"
                        : "Grant new deadline"}
                    </button>
                  </div>
                </section>
              );
            })}
          </div>
        ) : (
          <Empty
            title="No pending access requests"
            detail="Student requests for expired tests will appear here."
            icon={Check}
          />
        )}
      </Card>
    </>
  );
}

function AttemptReview({
  testId,
  onClose,
  notify,
}: {
  testId: string;
  onClose: () => void;
  notify: (s: string) => void;
}) {
  const {
    data,
    error,
    loading,
    refresh: refreshAttempts,
  } = useResource<unknown[]>(`/tests/${testId}/attempts`);
  const rows = asArray<Data>(data);
  const [studentId, setStudentId] = useState(""),
    [marks, setMarks] = useState(""),
    [notes, setNotes] = useState(""),
    [selectedAttempt, setSelectedAttempt] = useState<Data | null>(null),
    [fileBusy, setFileBusy] = useState(""),
    [retryBusy, setRetryBusy] = useState(false),
    [hasReviewRequest, setHasReviewRequest] = useState(false);
  const refreshAttemptsRef = useRef(refreshAttempts);
  useEffect(() => {
    refreshAttemptsRef.current = refreshAttempts;
  }, [refreshAttempts]);
  useEffect(() => {
    const timer = window.setInterval(() => {
      if (
        rows.some((attempt) =>
          ["SUBMITTED", "PROCESSING"].includes(
            String(attempt.status ?? "").toUpperCase(),
          ),
        )
      ) {
        void refreshAttemptsRef.current();
      }
    }, 15_000);
    return () => window.clearInterval(timer);
  }, [rows]);
  useEffect(() => {
    if (!selectedAttempt?.id) return;
    const refreshed = rows.find(
      (attempt) => getId(attempt) === getId(selectedAttempt),
    );
    if (refreshed && refreshed !== selectedAttempt) {
      setSelectedAttempt(refreshed);
    }
  }, [rows, selectedAttempt]);
  const attemptFiles = asArray<Data>(selectedAttempt?.files);
  const hasOriginalPaperPdf = attemptFiles.some((file) =>
    /^paper-source-.+\.pdf$/i.test(String(file.originalName ?? "")),
  );
  const downloadableAttemptFiles = attemptFiles.filter(
    (file) =>
      !(
        hasOriginalPaperPdf &&
        /^paper-page-\d+\./i.test(String(file.originalName ?? ""))
      ),
  );
  async function update(path: string, body: unknown) {
    try {
      await api(path, json("PUT", body));
      await refreshAttempts();
      setSelectedAttempt(null);
      notify("Saved through the API.");
    } catch (e) {
      notify(e instanceof Error ? e.message : "Update failed.");
    }
  }
  async function downloadSubmissionFile(file: Data) {
    const fileId = String(file.id ?? "");
    if (!fileId) return;
    setFileBusy(fileId);
    try {
      const token = getToken();
      const response = await fetch(
        `${API_BASE}/api/tests/${testId}/attempts/files/${fileId}`,
        {
          headers: token ? { Authorization: `Bearer ${token}` } : {},
        },
      );
      if (!response.ok) {
        throw new Error(
          `Could not retrieve the submission file (${response.status}).`,
        );
      }
      const objectUrl = URL.createObjectURL(await response.blob());
      const link = document.createElement("a");
      link.href = objectUrl;
      link.download = String(file.originalName ?? "student-submission");
      document.body.append(link);
      link.click();
      link.remove();
      window.setTimeout(() => URL.revokeObjectURL(objectUrl), 30_000);
    } catch (e) {
      notify(
        e instanceof Error
          ? e.message
          : "Could not retrieve the submission file.",
      );
    } finally {
      setFileBusy("");
    }
  }
  async function retryAssessment() {
    if (!selectedAttempt?.id) return;
    setRetryBusy(true);
    try {
      await api(`/tests/${testId}/attempts/${selectedAttempt.id}/retry`, {
        method: "POST",
      });
      await refreshAttempts();
      setSelectedAttempt((current) =>
        current
          ? { ...current, status: "SUBMITTED", gradingError: null }
          : current,
      );
      notify("Assessment retry started. The result will update when ready.");
    } catch (e) {
      notify(e instanceof Error ? e.message : "Could not retry assessment.");
    } finally {
      setRetryBusy(false);
    }
  }
  return (
    <Card className="detail-card" id={`attempt-review-${testId}`}>
      <div className="card-heading">
        <div>
          <h2>Attempts & review actions</h2>
          <p>
            Review submissions and update marks. Access requests are managed
            separately.
          </p>
        </div>
        <button
          className="icon-button"
          onClick={onClose}
          aria-label="Close review"
        >
          <X />
        </button>
      </div>
      {loading ? (
        <div className="loading">
          <span className="spinner" />
          Loading attempts…
        </div>
      ) : error ? (
        <ErrorBox error={error} />
      ) : rows.length ? (
        <div className="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Student</th>
                <th>Student ID</th>
                <th>Submission</th>
                <th>Access request</th>
                <th>Marks</th>
                <th>Student review</th>
                <th>Details</th>
              </tr>
            </thead>
            <tbody>
              {rows.map((r, i) => {
                const sid = String(
                  r.studentId ?? r.student?.id ?? r.userId ?? "",
                );
                return (
                  <tr key={getId(r) || i}>
                    <td>{r.student?.fullName ?? r.student?.email ?? "—"}</td>
                    <td>{sid || "—"}</td>
                    <td>{r.status ?? formatDate(r.submittedAt)}</td>
                    <td>
                      {r.accessRequestedAt
                        ? `Requested ${formatDate(r.accessRequestedAt)}`
                        : "—"}
                    </td>
                    <td>
                      {r.marksObtained ?? "—"} / {r.totalMarks ?? "—"}
                    </td>
                    <td>
                      {r.reviewNote ? (
                        <div className="attempt-review-cell">
                          <b>Review requested</b>
                          <small>{formatDate(r.reviewSubmittedAt)}</small>
                          <span>{String(r.reviewNote).slice(0, 120)}</span>
                        </div>
                      ) : (
                        "—"
                      )}
                    </td>
                    <td>
                      {sid && (
                        <button
                          className="button secondary small-button"
                          onClick={() => {
                            setSelectedAttempt(r);
                            setStudentId(sid);
                            setMarks(String(r.marksObtained ?? ""));
                            setNotes(
                              String(
                                r.reviewResolution ??
                                  (r.reviewNote
                                    ? ""
                                    : (r.deficiencyNotes ?? "")),
                              ),
                            );
                            setHasReviewRequest(Boolean(r.reviewNote));
                          }}
                        >
                          View submission & review
                        </button>
                      )}
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
      ) : (
        <Empty
          title="No attempts returned"
          detail="Attempt data will appear here when students submit."
          icon={FileText}
        />
      )}
      {selectedAttempt && (
        <section className="attempt-detail-panel" aria-live="polite">
          <div className="card-heading">
            <div>
              <div className="eyebrow">STUDENT ATTEMPT</div>
              <h3>
                {selectedAttempt.student?.fullName ??
                  selectedAttempt.student?.email ??
                  "Student"}
              </h3>
              <p>
                {label(selectedAttempt.status)} · Submitted{" "}
                {formatDate(selectedAttempt.submittedAt)}
              </p>
            </div>
            <div className="button-row">
              {selectedAttempt.submittedAt &&
                String(selectedAttempt.status).toUpperCase() !== "COMPLETED" &&
                !selectedAttempt.aiAssessment && (
                <button
                  className="button secondary small-button"
                  disabled={retryBusy}
                  onClick={() => void retryAssessment()}
                >
                  {retryBusy ? "Retrying…" : "Retry assessment"}
                </button>
              )}
              <button
                className="icon-button"
                aria-label="Close selected attempt details"
                onClick={() => setSelectedAttempt(null)}
              >
                <X size={17} />
              </button>
            </div>
          </div>

          {selectedAttempt.reviewNote && (
            <section className="student-review-request">
              <div>
                <h4>Student mark-review request</h4>
                <small>
                  Sent {formatDate(selectedAttempt.reviewSubmittedAt)}
                  {selectedAttempt.reviewResolvedAt
                    ? ` · Resolved ${formatDate(selectedAttempt.reviewResolvedAt)}`
                    : " · Awaiting admin response"}
                </small>
              </div>
              <p>{selectedAttempt.reviewNote}</p>
              {selectedAttempt.reviewResolution && (
                <div className="review-resolution">
                  <b>Admin response</b>
                  <p>{selectedAttempt.reviewResolution}</p>
                </div>
              )}
            </section>
          )}

          <section className="submission-files">
            <h4>Uploaded answers</h4>
            {downloadableAttemptFiles.length ? (
              downloadableAttemptFiles.map((file) => (
                <div className="submission-file-row" key={getId(file)}>
                  <div>
                    <b>
                      {/^paper-source-.+\.pdf$/i.test(
                        String(file.originalName ?? ""),
                      )
                        ? "Original complete-paper PDF"
                        : (file.originalName ?? "Submission file")}
                    </b>
                    <small>
                      {file.questionNumber
                        ? `Question ${file.questionNumber} · `
                        : "Complete paper · "}
                      {Math.max(
                        1,
                        Math.round(Number(file.sizeBytes ?? 0) / 1024),
                      )}{" "}
                      KB
                    </small>
                  </div>
                  <button
                    className="button secondary small-button"
                    disabled={fileBusy === getId(file)}
                    onClick={() => void downloadSubmissionFile(file)}
                  >
                    <Download size={14} />
                    {fileBusy === getId(file) ? "Downloading…" : "Download"}
                  </button>
                </div>
              ))
            ) : (
              <p className="subtle-padding">
                No uploaded files for this attempt.
              </p>
            )}
            {(() => {
              const uploadedPages = attemptFiles
                .filter((file) =>
                  String(file.mimeType ?? "").startsWith("image/"),
                )
                .map((file) => {
                  const originalName = String(
                    file.originalName ?? "Student answer",
                  );
                  const pageMatch = originalName.match(
                    /^(?:paper|question-\d+)-page-(\d+)\./i,
                  );
                  return {
                    id: getId(file),
                    originalName,
                    mimeType: String(file.mimeType ?? ""),
                    pageNumbers: [1],
                    ...(pageMatch
                      ? { displayPageNumber: Number(pageMatch[1]) }
                      : {}),
                  };
                });
              return uploadedPages.length ? (
                <details className="admin-answer-pages">
                  <summary>Preview all uploaded answer pages</summary>
                  <PageImageGallery
                    endpoint={`/api/tests/${testId}/attempts/files`}
                    files={uploadedPages}
                  />
                </details>
              ) : null;
            })()}
          </section>

          <section className="admin-assessment-report">
            <h4>Assessment report</h4>
            {selectedAttempt.aiAssessment?.deficiencyReport ? (
              <>
                <FormattedContent
                  className="attempt-overall-report formatted-content"
                  content={selectedAttempt.aiAssessment.deficiencyReport}
                />
                {asArray<Data>(selectedAttempt.aiAssessment.questions).map(
                  (question) => (
                    <article
                      className="admin-question-report"
                      key={question.questionNumber}
                    >
                      <div className="criterion-heading">
                        <b>Question {question.questionNumber}</b>
                        <span>
                          {question.marksAwarded} / {question.marksAvailable}
                        </span>
                      </div>
                      <details className="admin-answer-pages" open>
                        <summary>
                          View original question and ideal solution pages
                        </summary>
                        <h5>Original question page</h5>
                        <PageImageGallery
                          endpoint={`/api/tests/${testId}/source-files`}
                          emptyMessage="No original question page is mapped to this question."
                          files={sourcePageImageFiles(
                            Number(question.questionNumber),
                            asArray<Data>(selectedAttempt.testQuestions),
                            asArray<Data>(selectedAttempt.solutionSourceFiles),
                            "questionSourceLocations",
                          )}
                        />
                        <h5>Ideal solution page</h5>
                        <PageImageGallery
                          endpoint={`/api/tests/${testId}/source-files`}
                          emptyMessage="No original ideal-solution page is mapped to this question."
                          files={sourcePageImageFiles(
                            Number(question.questionNumber),
                            asArray<Data>(selectedAttempt.testQuestions),
                            asArray<Data>(selectedAttempt.solutionSourceFiles),
                            "solutionSourceLocations",
                          )}
                        />
                      </details>
                      {asArray<Data>(selectedAttempt.files).length > 0 && (
                        <details className="admin-answer-pages">
                          <summary>View your original answer page</summary>
                          <PageImageGallery
                            endpoint={`/api/tests/${testId}/attempts/files`}
                            emptyMessage="No original answer page is mapped to this question."
                            files={(() => {
                              const studentPages = new Set(
                                asArray<number>(question.studentPageNumbers),
                              );
                              const matching = asArray<Data>(selectedAttempt.files)
                                .filter(
                                  (file) =>
                                    file.questionNumber == null ||
                                    Number(file.questionNumber) ===
                                      Number(question.questionNumber),
                                )
                                .map((file) => {
                                  const originalName = String(
                                    file.originalName ?? "Student answer",
                                  );
                                  const mimeType = String(file.mimeType ?? "");
                                  const completePage = originalName.match(
                                    /^paper-page-(\d+)\./i,
                                  );
                                  const pageNumber = completePage
                                    ? Number(completePage[1])
                                    : null;
                                  return {
                                    id: getId(file),
                                    originalName,
                                    mimeType,
                                    pageNumbers:
                                      pageNumber !== null
                                        ? studentPages.has(pageNumber)
                                          ? [1]
                                          : []
                                        : mimeType.startsWith("image/")
                                          ? [1]
                                          : studentPages.size
                                            ? [...studentPages]
                                            : [],
                                    ...(pageNumber !== null
                                      ? { displayPageNumber: pageNumber }
                                      : {}),
                                  };
                                });
                              const hasRenderedPaperPages = matching.some(
                                (file) =>
                                  /^paper-page-\d+\./i.test(file.originalName),
                              );
                              return matching.filter(
                                (file) =>
                                  !(
                                    hasRenderedPaperPages &&
                                    /^paper-source-/i.test(file.originalName)
                                  ),
                              );
                            })()}
                          />
                        </details>
                      )}
                      <FormattedContent
                        className="formatted-content"
                        content={question.deficiencyReport}
                      />
                      {asArray<Data>(question.criterionResults).map(
                        (criterion, index) => (
                          <div
                            className="admin-criterion-report"
                            key={`${criterion.criterion}-${index}`}
                          >
                            <b>
                              {criterion.criterion} · {criterion.marksAwarded}/
                              {criterion.marksAvailable}
                            </b>
                            <FormattedContent
                              className="formatted-content"
                              content={criterion.feedback}
                            />
                            {asArray<string>(criterion.evidence).length > 0 && (
                              <p className="criterion-evidence">
                                <b>Quoted answer evidence:</b>{" "}
                                {asArray<string>(criterion.evidence)
                                  .map((item) => `“${item}”`)
                                  .join("; ")}
                              </p>
                            )}
                          </div>
                        ),
                      )}
                    </article>
                  ),
                )}
              </>
            ) : (
              <p className="subtle-padding">
                {selectedAttempt.gradingError ??
                  (String(selectedAttempt.status).toUpperCase() ===
                  "PROCESSING"
                    ? "AI assessment is running. This report will update automatically."
                    : String(selectedAttempt.status).toUpperCase() ===
                        "SUBMITTED"
                      ? "AI assessment is queued. It will start when the server grading worker runs; if this remains queued, check the HostGator cPanel cron job."
                      : "No AI assessment report is available for this attempt. Retry assessment to queue it for AI grading.")}
              </p>
            )}
          </section>
        </section>
      )}
      <div className="review-actions">
        <h3>Update selected attempt</h3>
        <div className="inline-fields">
          <label>
            Marks
            <input
              type="number"
              min="0"
              step="0.01"
              value={marks}
              onChange={(e) => setMarks(e.target.value)}
            />
          </label>
        </div>
        <label className="field-label">
          Student ID
          <input
            value={studentId}
            onChange={(e) => setStudentId(e.target.value)}
            placeholder="UUID from attempt row"
          />
        </label>
        <label className="field-label">
          Admin response / review notes
          <input
            value={notes}
            onChange={(e) => setNotes(e.target.value)}
            placeholder="Optional feedback or resolution"
          />
        </label>
        <div className="button-row">
          <button
            className="button primary"
            disabled={
              !studentId ||
              (marks === "" && !hasReviewRequest) ||
              (hasReviewRequest && !notes.trim())
            }
            onClick={() =>
              void update(`/tests/${testId}/marks/${studentId}`, {
                ...(marks !== "" ? { marksObtained: Number(marks) } : {}),
                ...(notes ? { deficiencyNotes: notes } : {}),
                ...(hasReviewRequest ? { reviewResolution: notes } : {}),
              })
            }
          >
            {hasReviewRequest
              ? marks === ""
                ? "Send response & resolve review"
                : "Save marks & resolve review"
              : "Save marks"}
          </button>
        </div>
      </div>
    </Card>
  );
}
function CreateTest({
  onClose,
  notify,
}: {
  onClose: () => void;
  notify: (s: string) => void;
}) {
  const [subjectId, setSubjectId] = useState("");
  const { data: subjectData } = useResource<unknown[]>("/subjects/list");
  const subjects = asArray<Data>(subjectData);
  const [title, setTitle] = useState(""),
    [description, setDescription] = useState(""),
    [date, setDate] = useState(""),
    [deadline, setDeadline] = useState(""),
    [questions, setQuestions] = useState<QuestionDraft[]>([
      {
        questionText: "",
        idealAnswer: "",
        difficulty: "MODERATE",
        markingText: "Core answer | 10",
      },
    ]),
    [importFiles, setImportFiles] = useState<File[]>([]),
    [importErrors, setImportErrors] = useState<string[]>([]),
    [busy, setBusy] = useState(false),
    [error, setError] = useState("");
  const totalMarks = questions.reduce(
    (total, questionDraft) =>
      total +
      questionDraft.markingText
        .split("\n")
        .filter(Boolean)
        .reduce((sum, line) => {
          const allocation = line.match(/\|\s*(\d+(?:\.\d{1,2})?)\s*$/);
          return sum + Number(allocation?.[1] ?? 0);
        }, 0),
    0,
  );
  function updateQuestion(index: number, patch: Partial<QuestionDraft>) {
    setQuestions((current) =>
      current.map((item, itemIndex) =>
        itemIndex === index ? { ...item, ...patch } : item,
      ),
    );
  }
  async function importQuestions() {
    if (!importFiles.length) return;
    setBusy(true);
    setError("");
    setImportErrors([]);
    const form = new FormData();
    importFiles.forEach((file) => form.append("files[]", file, file.name));
    try {
      const parsed = await api<Data>("/tests/questions/preview", {
        method: "POST",
        body: form,
      });
      const groups = asArray<Data>(parsed.groups);
      setImportErrors([
        ...asArray<string>(parsed.errors),
        ...groups.flatMap((group) => asArray<string>(group.errors)),
      ]);
      if (groups.length) {
        setQuestions(
          groups.map((group) => ({
            questionText: String(group.questionText ?? ""),
            idealAnswer: String(group.solution ?? ""),
            difficulty: ["EASY", "MODERATE", "DIFFICULT"].includes(
              String(group.difficulty),
            )
              ? group.difficulty
              : "MODERATE",
            markingText: asArray<Data>(group.markingScheme)
              .map((criterion) => {
                const text = String(criterion.criterion ?? "");
                return /\|\s*\d+(?:\.\d{1,2})?\s*$/.test(text)
                  ? text
                  : `${text} | ${criterion.marks ?? 1}`;
              })
              .join("\n"),
            questionSourceLocations: asArray<Data>(
              group.questionSourceLocations,
            ).map((location) => ({
              sourceIndex: Number(location.sourceIndex),
              pageNumbers: asArray<number>(location.pageNumbers),
            })),
            solutionSourceLocations: asArray<Data>(
              group.solutionSourceLocations,
            ).map((location) => ({
              sourceIndex: Number(location.sourceIndex),
              pageNumbers: asArray<number>(location.pageNumbers),
            })),
          })),
        );
      }
    } catch (ex) {
      setError(
        ex instanceof Error
          ? ex.message
          : "Could not parse this question file.",
      );
    } finally {
      setBusy(false);
    }
  }
  async function submit(e: FormEvent) {
    e.preventDefault();
    setError("");
    if (!subjectId) {
      setError("Choose a subject for this test.");
      return;
    }
    if (
      questions.length === 0 ||
      questions.some(
        (item) => !item.questionText.trim() || !item.idealAnswer.trim(),
      )
    ) {
      setError(
        "Every question requires both question text and an ideal answer.",
      );
      return;
    }
    let questionPayload: Data[];
    try {
      questionPayload = questions.map((item, index) => {
        const markingScheme: { criterion: string; marks: number }[] = [];
        for (const line of item.markingText
          .split("\n")
          .filter((entry) => entry.trim())) {
          const match = line
            .trim()
            .match(/^(.*?)\s*\|\s*(\d+(?:\.\d{1,2})?)\s*$/);
          if (!match || !match[1].trim() || Number(match[2]) <= 0) {
            throw new Error(
              `Question ${index + 1} marking criteria must use “criterion | marks” on each line.`,
            );
          }
          markingScheme.push({
            criterion: match[1].trim(),
            marks: Number(match[2]),
          });
        }
        if (!markingScheme.length) {
          throw new Error(
            `Question ${index + 1} needs at least one marking criterion.`,
          );
        }
        return {
          questionNumber: index + 1,
          questionText: item.questionText,
          idealAnswer: item.idealAnswer,
          difficulty: item.difficulty,
          ...(item.questionSourceLocations?.length
            ? { questionSourceLocations: item.questionSourceLocations }
            : {}),
          ...(item.solutionSourceLocations?.length
            ? { solutionSourceLocations: item.solutionSourceLocations }
            : {}),
          marks: markingScheme.reduce(
            (sum, criterion) => Math.round((sum + criterion.marks) * 100) / 100,
            0,
          ),
          markingScheme,
        };
      });
    } catch (ex) {
      setError(
        ex instanceof Error ? ex.message : "Check the marking criteria.",
      );
      return;
    }
    const marksSum = questionPayload.reduce(
      (sum, item) => Math.round((sum + Number(item.marks)) * 100) / 100,
      0,
    );
    if (marksSum < 0.01) {
      setError(
        "Add at least one marking criterion with a positive mark allocation.",
      );
      return;
    }
    if (!deadline) {
      setError("A deadline is required for an online test.");
      return;
    }
    setBusy(true);
    try {
      const created = await api<Data>(
        "/tests",
        json("POST", {
          title,
          description,
          totalMarks: marksSum,
          subjectId,
          ...(date ? { testDate: new Date(date).toISOString() } : {}),
          deadlineAt: new Date(deadline).toISOString(),
          questions: questionPayload,
        }),
      );
      if (importFiles.length) {
        const sourceForm = new FormData();
        importFiles.forEach((file) =>
          sourceForm.append("files[]", file, file.name),
        );
        try {
          await api(`/tests/${getId(created)}/source-files`, {
            method: "POST",
            body: sourceForm,
          });
        } catch (sourceError) {
          notify(
            `Test created, but the original source files could not be retained: ${
              sourceError instanceof Error
                ? sourceError.message
                : "upload failed"
            }`,
          );
          onClose();
          return;
        }
      }
      notify("Test created.");
      onClose();
    } catch (ex) {
      setError(ex instanceof Error ? ex.message : "Test creation failed.");
    } finally {
      setBusy(false);
    }
  }
  return (
    <div className="modal-backdrop" role="presentation" onClick={onClose}>
      <section
        className="modal"
        role="dialog"
        aria-modal="true"
        aria-labelledby="create-title"
        onClick={(e) => e.stopPropagation()}
      >
        <div className="card-heading">
          <div>
            <div className="eyebrow">TEST AUTHORING</div>
            <h2 id="create-title">Create a test</h2>
          </div>
          <button className="icon-button" onClick={onClose} aria-label="Close">
            <X />
          </button>
        </div>
        <p className="api-note">
          A test requires a subject, deadline, question text, ideal answer,
          marks, and marking scheme. Upload multiple PDFs or DOCX files to
          extract their combined content, then review and edit each extracted
          field before creating the test. Original source documents are retained
          for administrator download.
        </p>
        <form className="form-stack" onSubmit={submit}>
          <label>
            Subject
            <select
              required
              value={subjectId}
              onChange={(e) => setSubjectId(e.target.value)}
            >
              <option value="">Choose subject</option>
              {subjects.map((f) => (
                <option key={getId(f)} value={getId(f)}>
                  {getTitle(f)}
                </option>
              ))}
            </select>
          </label>
          <label>
            Test title
            <input
              required
              maxLength={200}
              value={title}
              onChange={(e) => setTitle(e.target.value)}
            />
          </label>
          <label>
            Description
            <textarea
              rows={2}
              value={description}
              onChange={(e) => setDescription(e.target.value)}
            />
          </label>
          <div className="question-import">
            <label>
              Import questions, solutions, or marking schemes (PDF or DOCX)
              <input
                type="file"
                accept=".pdf,.docx"
                multiple
                onChange={(e) =>
                  setImportFiles(Array.from(e.target.files ?? []))
                }
              />
            </label>
            <button
              type="button"
              className="button secondary"
              disabled={!importFiles.length || busy}
              onClick={() => void importQuestions()}
            >
              {busy ? "Reading files…" : "Preview and edit questions"}
            </button>
            {importFiles.length > 0 && (
              <small>
                {importFiles.length} file{importFiles.length === 1 ? "" : "s"}{" "}
                selected: {importFiles.map((file) => file.name).join(", ")}
              </small>
            )}
            <small>
              Files are combined in selection order. Use Question#1 (easy),
              Answer#1, Marking#1, then repeat. Marking lines use “criterion |
              marks”; decimals such as 0.2 and 0.5 are supported.
            </small>
          </div>
          {importErrors.map((importError, index) => (
            <div className="alert warning" key={`${index}-${importError}`}>
              {importError}
            </div>
          ))}
          <div className="inline-fields">
            <label>
              Total marks (calculated from the marking schemes)
              <input
                type="number"
                min="0.01"
                step="0.01"
                value={totalMarks}
                readOnly
              />
            </label>
          </div>
          <div className="inline-fields">
            <label>
              Test date
              <input
                type="datetime-local"
                value={date}
                onChange={(e) => setDate(e.target.value)}
              />
            </label>
            <label>
              Deadline
              <input
                type="datetime-local"
                required
                value={deadline}
                onChange={(e) => setDeadline(e.target.value)}
              />
            </label>
          </div>
          {questions.map((questionDraft, index) => (
            <Card key={index} className="question-draft">
              <div className="card-heading">
                <div>
                  <h3>Question {index + 1}</h3>
                  <p>
                    Review the question, answer, difficulty, and marking scheme.
                  </p>
                </div>
                {questions.length > 1 && (
                  <button
                    type="button"
                    className="button secondary small-button"
                    onClick={() =>
                      setQuestions(
                        questions.filter((_, itemIndex) => itemIndex !== index),
                      )
                    }
                  >
                    Remove
                  </button>
                )}
              </div>
              <label>
                Question
                <textarea
                  required
                  rows={3}
                  value={questionDraft.questionText}
                  onChange={(e) =>
                    updateQuestion(index, { questionText: e.target.value })
                  }
                />
              </label>
              <label>
                Ideal answer
                <textarea
                  required
                  rows={3}
                  value={questionDraft.idealAnswer}
                  onChange={(e) =>
                    updateQuestion(index, { idealAnswer: e.target.value })
                  }
                />
              </label>
              <label>
                Difficulty
                <select
                  value={questionDraft.difficulty}
                  onChange={(e) =>
                    updateQuestion(index, {
                      difficulty: e.target.value as QuestionDraft["difficulty"],
                    })
                  }
                >
                  <option value="EASY">Easy</option>
                  <option value="MODERATE">Medium</option>
                  <option value="DIFFICULT">Hard</option>
                </select>
              </label>
              <label>
                Marking scheme — one “criterion | marks” per line
                <textarea
                  required
                  rows={3}
                  value={questionDraft.markingText}
                  onChange={(e) =>
                    updateQuestion(index, { markingText: e.target.value })
                  }
                />
              </label>
              <span className="status">
                Question marks:{" "}
                {questionDraft.markingText
                  .split("\n")
                  .reduce(
                    (sum, line) =>
                      sum +
                      Number(
                        line.match(/\|\s*(\d+(?:\.\d{1,2})?)\s*$/)?.[1] ?? 0,
                      ),
                    0,
                  )}
              </span>
            </Card>
          ))}
          <button
            type="button"
            className="button secondary"
            onClick={() =>
              setQuestions([
                ...questions,
                {
                  questionText: "",
                  idealAnswer: "",
                  difficulty: "MODERATE",
                  markingText: "Core answer | 1",
                },
              ])
            }
          >
            <Plus size={15} /> Add question
          </button>
          {error && <ErrorBox error={error} />}
          <div className="button-row">
            <button
              type="button"
              className="button secondary"
              onClick={onClose}
            >
              Cancel
            </button>
            <button className="button primary" disabled={busy}>
              {busy ? "Creating?" : "Create test"}
              <Check size={16} />
            </button>
          </div>
        </form>
      </section>
    </div>
  );
}
function Queries({ admin }: { admin: boolean }) {
  const {
    data,
    error,
    loading,
    refresh: refreshQueries,
  } = useResource<unknown[]>("/student-queries");
  const rows = asArray<Data>(data);
  const [title, setTitle] = useState(""),
    [message, setMessage] = useState(""),
    [active, setActive] = useState<Data | null>(null),
    [reply, setReply] = useState(""),
    [notice, setNotice] = useState("");
  async function create(e: FormEvent) {
    e.preventDefault();
    setNotice("");
    try {
      await api("/student-queries", json("POST", { title, message }));
      setNotice("Your query was sent.");
      setTitle("");
      setMessage("");
      refreshQueries();
    } catch (ex) {
      setNotice(ex instanceof Error ? ex.message : "Could not send query.");
    }
  }
  async function sendReply() {
    if (!active) return;
    try {
      const updatedQuery = await api<Data>(
        `/student-queries/${getId(active)}/reply`,
        json("POST", { response: reply }),
      );
      setNotice("Reply submitted.");
      setActive(updatedQuery);
      setReply("");
      refreshQueries();
    } catch (ex) {
      setNotice(ex instanceof Error ? ex.message : "Reply failed.");
    }
  }
  return (
    <>
      <Heading
        eyebrow={admin ? "SUPPORT INBOX" : "HELP & SUPPORT"}
        title={admin ? "Student Queries" : "Admin Contact"}
        description={
          admin
            ? "Read student queries and reply through the protected support endpoint."
            : "Send a message to the school administration team."
        }
      />
      {notice && (
        <div
          className={`alert ${notice.includes("sent") || notice.includes("submitted") ? "success" : "error"}`}
        >
          {notice}
        </div>
      )}
      <div className="queries-layout">
        <Card>
          <div className="card-heading">
            <div>
              <h2>{admin ? "Incoming queries" : "Your messages"}</h2>
              <p>{rows.length} items</p>
            </div>
          </div>
          {loading ? (
            <div className="loading">
              <span className="spinner" />
              Loading queries…
            </div>
          ) : error ? (
            <ErrorBox error={error} />
          ) : rows.length ? (
            rows.map((q, i) => (
              <button
                className={`query-row ${active && getId(active) === getId(q) ? "selected" : ""}`}
                key={getId(q) || i}
                onClick={() => {
                  setActive(q);
                  setReply("");
                }}
              >
                <span className="query-icon">
                  <MessageCircle size={17} />
                </span>
                <span className="query-content">
                  <b>{q.title ?? "Untitled query"}</b>
                  <small>
                    {q.student?.fullName ??
                      q.student?.email ??
                      q.user?.email ??
                      "Your message"}{" "}
                    · {formatDate(q.createdAt)}
                  </small>
                </span>
                <span className="status">{q.status ?? "Open"}</span>
              </button>
            ))
          ) : (
            <Empty
              title={admin ? "Inbox is clear" : "No messages yet"}
              detail={
                admin
                  ? "New student messages will appear here."
                  : "Create a message and follow responses here."
              }
              icon={MessageCircle}
            />
          )}
        </Card>
        {admin ? (
          <Card className="query-detail">
            {active ? (
              <>
                <div className="card-heading">
                  <div>
                    <div className="eyebrow">QUERY DETAILS</div>
                    <h2>{active.title ?? "Student query"}</h2>
                  </div>
                </div>
                <div className="query-body">
                  {active.message ??
                    active.content ??
                    "No message body returned."}
                </div>
                {active.response && (
                  <div className="response-box">
                    <b>Previous response</b>
                    <p>{active.response}</p>
                  </div>
                )}
                <label className="field-label">
                  Reply
                  <textarea
                    rows={5}
                    maxLength={4000}
                    value={reply}
                    onChange={(e) => setReply(e.target.value)}
                    placeholder="Write a response…"
                  />
                </label>
                <button
                  className="button primary"
                  disabled={!reply.trim()}
                  onClick={() => void sendReply()}
                >
                  Send reply <Send size={16} />
                </button>
              </>
            ) : (
              <Empty
                title="Choose a query"
                detail="Select a message to read and respond."
                icon={MessageCircle}
              />
            )}
          </Card>
        ) : (
          <Card>
            <div className="card-heading">
              <div>
                <h2>Contact administration</h2>
                <p>Messages are sent to the authorized support inbox.</p>
              </div>
            </div>
            <form className="form-stack" onSubmit={create}>
              <label>
                Subject
                <input
                  required
                  maxLength={200}
                  value={title}
                  onChange={(e) => setTitle(e.target.value)}
                  placeholder="How can we help?"
                />
              </label>
              <label>
                Message
                <textarea
                  required
                  rows={7}
                  maxLength={4000}
                  value={message}
                  onChange={(e) => setMessage(e.target.value)}
                  placeholder="Describe your question or concern…"
                />
              </label>
              <button className="button primary">
                Send message <Send size={16} />
              </button>
            </form>
          </Card>
        )}
      </div>
    </>
  );
}
function Analytics() {
  const { data, error, loading } = useResource<Data>(
    "/admin/analytics/overview",
  );
  const obj = data ?? {};
  const cards = Object.entries(obj)
    .filter(([, v]) => typeof v === "number" || typeof v === "string")
    .slice(0, 10);
  return (
    <>
      <Heading
        eyebrow="SCHOOL INSIGHTS"
        title="Analytics"
        description="Summary metrics supplied by the protected analytics API."
      />
      {loading ? (
        <LoadingCard />
      ) : error ? (
        <ErrorBox error={error} />
      ) : cards.length ? (
        <div className="metric-grid analytics-grid">
          {cards.map(([k, v], i) => (
            <Metric
              key={k}
              icon={[BarChart3, Users, FileText, Trophy][i % 4]}
              label={k.replace(/([A-Z])/g, " $1").replaceAll("_", " ")}
              value={String(v)}
              tone={["blue", "green", "amber"][i % 3]}
            />
          ))}
        </div>
      ) : (
        <Card>
          <Empty
            title="No summary metrics returned"
            detail="The analytics overview returned no simple metrics. Detailed filters and charts need confirmed response schema; no sample data is fabricated."
            icon={BarChart3}
          />
        </Card>
      )}
      <div className="api-note">
        Metrics reflect the current analytics summary scope and are supplied by
        the server; values are not estimated from the visible student list.
      </div>
    </>
  );
}
function ChangePassword({
  required = false,
  onDone,
}: {
  required?: boolean;
  onDone?: () => void;
}) {
  const [next, setNext] = useState(""),
    [confirm, setConfirm] = useState(""),
    [message, setMessage] = useState(""),
    [busy, setBusy] = useState(false);
  async function submit(e: FormEvent) {
    e.preventDefault();
    setMessage("");
    if (next !== confirm) {
      setMessage("New passwords do not match.");
      return;
    }
    setBusy(true);
    try {
      await api(
        "/auth/change-password",
        json("POST", { newPassword: next }),
      );
      setMessage("Password changed. Please sign in again with your new password.");
      setNext("");
      setConfirm("");
      onDone?.();
    } catch (ex) {
      setMessage(
        ex instanceof Error ? ex.message : "Could not change password.",
      );
    } finally {
      setBusy(false);
    }
  }
  return (
    <>
      <Heading
        eyebrow="ACCOUNT SECURITY"
        title="My Password"
        description="Set a new password for your account."
      />
      {required && (
        <div className="alert warning">
          <ShieldCheck size={18} /> Your account requires a password reset
          before continuing.
        </div>
      )}
      <Card className="password-card">
        <div className="card-heading">
          <div>
            <h2>Change your password</h2>
            <p>Minimum 12 characters. You will need to sign in again afterward.</p>
          </div>
          <ShieldCheck size={20} />
        </div>
        <form className="form-stack" onSubmit={submit}>
          <label>
            New password
            <input
              type="password"
              autoComplete="new-password"
              minLength={12}
              maxLength={200}
              required
              value={next}
              onChange={(e) => setNext(e.target.value)}
            />
          </label>
          <label>
            Confirm new password
            <input
              type="password"
              autoComplete="new-password"
              minLength={12}
              maxLength={200}
              required
              value={confirm}
              onChange={(e) => setConfirm(e.target.value)}
            />
          </label>
          {message && (
            <div className={`alert ${message.startsWith("Password changed") ? "success" : "error"}`} role="status">
              {message}
            </div>
          )}
          <button className="button primary" disabled={busy}>
            {busy ? "Updating…" : "Change password"}
            <Check size={16} />
          </button>
        </form>
      </Card>
    </>
  );
}

function ManagedPasswords() {
  const [targetQuery, setTargetQuery] = useState(""),
    [targets, setTargets] = useState<Data[]>([]),
    [target, setTarget] = useState<Data | null>(null),
    [newTargetPassword, setNewTargetPassword] = useState(""),
    [targetCurrentPassword, setTargetCurrentPassword] = useState(""),
    [targetMessage, setTargetMessage] = useState(""),
    [targetBusy, setTargetBusy] = useState(false);
  async function searchTarget(e: FormEvent) {
    e.preventDefault();
    setTargetMessage("");
    if (targetQuery.trim().length < 2) {
      setTargetMessage("Enter at least two characters of an email address.");
      return;
    }
    try {
      const found = await api<unknown[]>(
        `/users/search?email=${encodeURIComponent(targetQuery.trim())}`,
      );
      setTargets(asArray<Data>(found));
      setTarget(null);
      if (!asArray<Data>(found).length)
        setTargetMessage("No matching accounts found.");
    } catch (ex) {
      setTargetMessage(
        ex instanceof Error ? ex.message : "Could not search accounts.",
      );
    }
  }
  async function resetTarget(e: FormEvent) {
    e.preventDefault();
    if (!target) return;
    setTargetBusy(true);
    setTargetMessage("");
    try {
      await api(
        `/team-members/${getId(target)}/password`,
        json("PUT", {
          newPassword: newTargetPassword,
          ...(target.role === "admin"
            ? { currentPassword: targetCurrentPassword }
            : {}),
        }),
      );
      setTargetMessage(
        "Password reset. The user must choose a new password at next sign-in.",
      );
      setNewTargetPassword("");
      setTargetCurrentPassword("");
      setTarget(null);
    } catch (ex) {
      setTargetMessage(
        ex instanceof Error ? ex.message : "Could not reset this password.",
      );
    } finally {
      setTargetBusy(false);
    }
  }
  return (
    <>
      <Heading
        eyebrow="ACCOUNT SECURITY"
        title="Manage Passwords"
        description="Reset another account’s password. You cannot reset your own account here."
      />
      <Card className="password-card">
          <div className="card-heading">
            <div>
              <h2>Reset another account</h2>
              <p>
                Search by email. Student password resets require no current
                password; a super-admin resetting another non-super-admin team
                member must verify their current password.
              </p>
            </div>
          </div>
          <form className="inline-fields" onSubmit={searchTarget}>
            <label>
              Search email
              <input
                type="search"
                required
                minLength={2}
                value={targetQuery}
                onChange={(e) => setTargetQuery(e.target.value)}
                placeholder="Start with an email fragment"
              />
            </label>
            <button className="button secondary">Search</button>
          </form>
          {targets.map((item) => (
            <button
              key={getId(item)}
              className={`tree-row ${getId(target ?? {}) === getId(item) ? "tree-selected" : ""}`}
              onClick={() => setTarget(item)}
            >
              <ShieldCheck size={16} />
              <span>
                {item.email} · {item.role}
              </span>
              <ChevronRight size={16} />
            </button>
          ))}
          {target && (
            <form className="form-stack compact-form" onSubmit={resetTarget}>
              {target.role === "admin" && (
                <label>
                  Your current password
                  <input
                    type="password"
                    required
                    autoComplete="current-password"
                    value={targetCurrentPassword}
                    onChange={(e) => setTargetCurrentPassword(e.target.value)}
                  />
                </label>
              )}
              <label>
                New temporary password
                <input
                  type="password"
                  required
                  minLength={9}
                  maxLength={200}
                  autoComplete="new-password"
                  value={newTargetPassword}
                  onChange={(e) => setNewTargetPassword(e.target.value)}
                />
              </label>
              <button className="button primary" disabled={targetBusy}>
                {targetBusy ? "Resetting…" : "Reset password"}
              </button>
            </form>
          )}
          {targetMessage && (
            <div
              className={`alert ${targetMessage.startsWith("Password reset") ? "success" : "error"}`}
              role="status"
            >
              {targetMessage}
            </div>
          )}
      </Card>
    </>
  );
}
export default App;
