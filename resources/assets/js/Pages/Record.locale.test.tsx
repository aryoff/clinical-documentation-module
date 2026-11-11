import React, { useEffect } from "react";
import { readFileSync } from "node:fs";
import path from "node:path";
import { render, screen, within } from "@testing-library/react";
import { afterEach, describe, expect, it, vi } from "vitest";
import { usePage } from "@inertiajs/react";
import type { Config } from "ziggy-js";
import RecordPage from "./Record";
import { HeaderProvider } from "@/Context/HeaderContext";
import { LoadingProvider } from "@/Context/LoadingContext";
import { TranslationProvider } from "@/Context/TranslationContext";
import { ZiggyProvider, useZiggy } from "@/Context/ZiggyContext";
import { loadModuleTranslations } from "@/lib/moduleTranslations";

vi.mock("@inertiajs/react", async (importOriginal) => ({
  ...(await importOriginal<typeof import("@inertiajs/react")>()),
  usePage: vi.fn(),
  Head: () => null,
  router: { on: () => () => {} },
}));

// This is an integration with the build's module catalog: a disabled module's
// unit is deliberately absent. The demo composition always runs these cases.
const enabled =
  JSON.parse(
    readFileSync(path.resolve(process.cwd(), "modules_statuses.json"), "utf8"),
  ).ClinicalDocumentation === true;
const bootstrap = {
  url: "http://localhost",
  port: null,
  defaults: {},
  routes: {},
} as Config;
const routeDescriptor = Object.getOwnPropertyDescriptor(globalThis, "route");
const ReadyRoutes = ({ children }: React.PropsWithChildren) => {
  const { beginAuthorizationRefresh, publishRouteSnapshot } = useZiggy();
  useEffect(() => {
    publishRouteSnapshot(beginAuthorizationRefresh(), {
      ziggy: bootstrap,
      moduleMenu: { structure: [], reverseLookup: {} },
    });
  }, [beginAuthorizationRefresh, publishRouteSnapshot]);
  return children;
};

afterEach(() => {
  vi.unstubAllGlobals();
  if (routeDescriptor)
    Object.defineProperty(globalThis, "route", routeDescriptor);
});

describe.skipIf(!enabled)("administration basis-state presentation", () => {
  it.each([
    ["en", "unreconciled", "Unreconciled"],
    ["id", "unreconciled", "Belum direkonsiliasi"],
    ["id", "set", "Ditetapkan"],
    ["id", "emergency-affirmed", "Ditegaskan dalam keadaan darurat"],
    ["id", "future-state", "future-state"],
  ])(
    "localizes %s/%s and preserves the source snapshot",
    async (locale, state, label) => {
      localStorage.clear();
      Object.defineProperty(globalThis, "route", {
        value: undefined,
        writable: true,
        configurable: true,
      });
      await loadModuleTranslations("ClinicalDocumentation", locale);
      vi.mocked(usePage).mockReturnValue({
        component: "ClinicalDocumentation::Record",
        url: "/record",
        props: {
          locale,
          criticalTranslations: { common: {}, auth: {}, menu: {} },
        },
      } as ReturnType<typeof usePage>);
      vi.stubGlobal(
        "fetch",
        vi
          .fn()
          .mockResolvedValue({
            ok: true,
            json: async () => ({
              global: {
                common: {},
                auth: {},
                menu: {},
                validation: {},
                pagination: {},
              },
              modules: {},
            }),
          }),
      );
      const props = {
        record: {
          patient_id: "patient-1",
          entries: [
            {
              id: "fact-1",
              occurred_at: "2026-10-12T10:00:00Z",
              kind: "medication_administration",
              fact: {
                type: "recorded",
                source_reference: "source-1",
                administration_id: "administration-1",
                registration_id: "registration-1",
                medicine: { product_name: "Authored medicine name" },
                outcome: "given",
                basis: { type: "none", state },
              },
            },
          ],
        },
      } as React.ComponentProps<typeof RecordPage>;
      const { container } = render(
        <TranslationProvider>
          <ZiggyProvider bootstrap={bootstrap}>
            <ReadyRoutes>
              <HeaderProvider hasHeader={false}>
                <LoadingProvider>
                  <RecordPage {...props} />
                </LoadingProvider>
              </HeaderProvider>
            </ReadyRoutes>
          </ZiggyProvider>
        </TranslationProvider>,
      );
      const basis = await screen.findByText(
        (text, element) =>
          element?.tagName === "P" &&
          text.includes(" · ") &&
          text.endsWith(label),
      );
      expect(basis).toBeInTheDocument();
      const details = container.querySelector("details")!;
      expect(
        within(details).getByText(
          (text, element) =>
            element?.tagName === "PRE" && text.includes(`"state": "${state}"`),
        ),
      ).toBeInTheDocument();
      expect(
        screen.getByText(
          (text, element) =>
            element?.getAttribute("data-slot") === "card-title" &&
            text.includes("Authored medicine name"),
        ),
      ).toBeInTheDocument();
    },
  );
});
