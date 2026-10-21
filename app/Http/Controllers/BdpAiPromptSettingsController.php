<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BdpAiPromptSettingsController extends Controller
{
    public function update(Request $request)
    {
        $template = (string) $request->input('ai_command_prompt_template', '');
        $template = trim($template);

        if ($template === '') {
            return response()->json([
                'success' => false,
                'message' => 'Prompt template cannot be empty.',
            ], 422);
        }

        $this->saveSetting('ai_command_prompt_template', $template);

        return response()->json([
            'success' => true,
            'message' => 'AI command prompt template saved successfully.',
        ]);
    }

    private function saveSetting(string $key, string $value): void
    {
        if (class_exists('App\\Models\\BdpSetting')) {
            $model = 'App\\Models\\BdpSetting';

            if (method_exists($model, 'setValue')) {
                $model::setValue($key, $value);
                return;
            }

            if (method_exists($model, 'updateOrCreate')) {
                $model::updateOrCreate(['key' => $key], ['value' => $value]);
                return;
            }
        }

        if (DB::getSchemaBuilder()->hasTable('bdp_settings')) {
            DB::table('bdp_settings')->updateOrInsert(
                ['key' => $key],
                ['value' => $value, 'updated_at' => now(), 'created_at' => now()]
            );
        }
    }
}
