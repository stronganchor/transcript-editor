<?php
/*
Plugin Name: * Transcript Editor
Description: A simple plugin to process and display transcripts for publishing online.
Version: 1.3
Author: Strong Anchor Tech
*/

// Additional function to send text to ChatGPT for editing
function edit_with_chatgpt($text, $model = 'gpt-3.5-turbo') {

    // Constructing the prompt
    $prompt = "I am giving you an excerpt from the exact transcript of a speech in English" .
              "Perform the following tasks: " .
              "1. Edit the text according to standard English punctuation rules. " .
              "2. Group the content logically into paragraphs with two sentences minimum per paragraph. " .
              "Each paragraph should be surrounded by HTML paragraph (p) tags. " .
              "Do not change or remove any of the original words except in cases of misspelling or obvious mistakes. " .
              "Here is the text: " . $text;

    // This assumes you have the chatGPT-interface plugin installed with the function of this name and a working API key saved in Tools > ChatGPT Interface
    return chatgpt_send_message($prompt, $model);
}

/*
 * Creates the shortcode [transcript_editor] to add the transcript editor to a page.
 */
function transcript_editor_shortcode() {
    ob_start(); // Start output buffering
    ?>
    <form method="post" action="">
        <textarea name="input_text" rows="10" cols="50"></textarea><br>
        <input type="submit" name="edit_transcript" value="Edit Transcript">
    </form>
    <?php
    if (isset($_POST['edit_transcript'])) {
        $processed_text = $_POST['input_text'];

        // Remove page numbers formatted as "Page X of Y"
        $processed_text = preg_replace('/Page \d+ of \d+/', '', $processed_text);

        // For equals signs
        $processed_text = preg_replace('/(\w)=+|=+(\w)/', '$1 $2', $processed_text);
        $processed_text = str_replace('=', '', $processed_text);

        // For sequences of dashes of any kind
        $processed_text = preg_replace('/(\w)(—+|-+)|=(—+|-+)(\w)/', '$1 $4', $processed_text);
        $processed_text = preg_replace('/—+|-+/', '', $processed_text);

        // For sequences of periods
        $processed_text = preg_replace('/\.\.+/', ' ', $processed_text);
        $processed_text = preg_replace('/…+/', ' ', $processed_text);
        		
        // Replace all instances of multiple spaces with a single space
        $processed_text = preg_replace('/\s+/', ' ', $processed_text);
		
		// Remove periods that are preceded by a space
        $processed_text = str_replace(' .', ' ', $processed_text);
		
		// Use esc_textarea() for sanitizing user-entered content
        $processed_text = esc_textarea($processed_text);

        // Make chunks of 1000-1500 characters (ending at the end of a sentence)
        $sentences = preg_split('/(?<=[.!?])\s+/', $processed_text, -1, PREG_SPLIT_NO_EMPTY);

		$current_length = 0;
        $chunk = '';
        $processed_text = '';
                
		foreach ($sentences as $sentence) {
            $sentence_length = mb_strlen($sentence);
            if (($current_length + $sentence_length) > 1500) {
                $edited_chunk = edit_with_chatgpt($chunk);
                if (!strpos($edited_chunk, '<p>')) {
                    $edited_chunk = '<p>' . $edited_chunk . '</p>';
                }
                $processed_text .= $edited_chunk;
                $chunk = '';
                $current_length = 0;
            }
            $chunk .= $sentence . ' ';
            $current_length += $sentence_length;
        }
        
        // Process the final chunk if there is any
        if (!empty($chunk)) {
            $edited_chunk = edit_with_chatgpt($chunk);
            if (!strpos($edited_chunk, '<p>')) {
                $edited_chunk = '<p>' . $edited_chunk . '</p>';
            }
            $processed_text .= $edited_chunk;
        }
    
        echo '<div id="output_area">';
        echo $processed_text;
        echo '</div>';
    }
    return ob_get_clean(); // Return the buffered content
}
add_shortcode('transcript_editor', 'transcript_editor_shortcode');

/*
 * Creates [custom_chatgpt] shortcode for iterating over a large body of text using a custom prompt
 */
function custom_chatgpt_shortcode() {
    ob_start(); ?>
    <form method="post" action="">
        <textarea name="input_text" rows="10" cols="50" placeholder="Enter text here"></textarea><br>
        <textarea name="custom_prompt" rows="2" cols="50" placeholder="Enter your prompt here"></textarea><br>
        <select name="model">
            <option value="gpt-3.5-turbo">GPT-3.5</option>
            <option value="gpt-4">GPT-4</option>
            <option value="gpt-4-turbo-preview">GPT-4 Turbo Preview</option>
        </select><br>
        <input type="submit" name="process_text" value="Process Text">
    </form>
    <?php
    if (isset($_POST['process_text'])) {
        $input_text = esc_textarea($_POST['input_text']);
        $custom_prompt = sanitize_text_field($_POST['custom_prompt']);
        $model = sanitize_text_field($_POST['model']); // Get the selected model

        $sentences = preg_split('/(?<=[.!?])\s+/', $input_text, -1, PREG_SPLIT_NO_EMPTY);
        $current_length = 0;
        $chunk = '';
        $processed_text = '';

        foreach ($sentences as $sentence) {
            $sentence_length = mb_strlen($sentence);
            if (($current_length + $sentence_length) > 1500) {
                $processed_text .= chatgpt_send_message($custom_prompt . "\n" . $chunk, $model);
                $chunk = '';
                $current_length = 0;
            }
            $chunk .= $sentence . ' ';
            $current_length += $sentence_length;
        }

        if (!empty($chunk)) {
            $processed_text .= chatgpt_send_message($custom_prompt . "\n" . $chunk, $model);
        }

        echo '<div id="output_area">' . $processed_text . '</div>';
    }
    return ob_get_clean();
}
add_shortcode('custom_chatgpt', 'custom_chatgpt_shortcode');

function add_paragraph_breaks_to_text($text) {
    if (mb_strlen($text) > 512000) {
        return 'Error: Text exceeds the maximum allowed length of 512,000 characters.';
    }

    $sentences = preg_split('/(?<=[.!?])\s+/', $text, -1, PREG_SPLIT_NO_EMPTY);

    // Base condition for recursion
    if (count($sentences) <= 4) {
        return '<p>' . implode(' ', $sentences) . '</p>';
    }

    $prompt = "Analyze the text provided and determine the logical divisions for the first four paragraphs based on the content's thematic changes and transitions. Please provide the output strictly as a set of comma-separated numbers representing the number of sentences in each subsequent paragraph. Do not include any additional explanations or text in the response. For example, if you identify that the text logically divides into paragraphs with 3, 2, 6, and 4 sentences respectively, please respond with: '3,2,6,4'.\n\nHere is the text:\n\n" . $text;
    
    $model = 'gpt-4-turbo-preview';
    $response = chatgpt_send_message($prompt, $model);
    
    // Use regex to find a sequence of comma-separated numbers
    preg_match('/\d+(?:,\s*\d+)*/', $response, $matches);
    
    if (empty($matches)) {
        return '<strong>Error: Received unexpected response format from the API: </strong>' . $response . ' <strong>Here is the original text:</strong> ' . $text . '</strong> ';
    }
    
    $csv = $matches[0];
    $paragraph_lengths = array_map('trim', explode(',', $csv));
    $processed_text = '';
    $current_sentence_index = 0;
    
    foreach ($paragraph_lengths as $length) {
        $length = (int) $length; // Ensure $length is an integer

        if ($length > 7) {
            // Recursively process chunks with more than 7 sentences
            $chunk = implode(' ', array_slice($sentences, $current_sentence_index, $length));
            $processed_text .= add_paragraph_breaks_to_text($chunk);
        } else {
            $paragraph = array_slice($sentences, $current_sentence_index, $length);
            $processed_text .= '<p>' . implode(' ', $paragraph) . '</p>';
        }
        $current_sentence_index += $length;
    }
    
    // Process any remaining text recursively
    $remaining_sentences = array_slice($sentences, $current_sentence_index);
    $remaining_text = implode(' ', $remaining_sentences);
    if (!empty($remaining_text)) {
        $processed_text .= add_paragraph_breaks_to_text($remaining_text);
    }

    return $processed_text;
}

// [gpt_paragraph_breaks] Shortcode - ask for user input and display it with paragraph breaks
function gpt_paragraph_breaks_shortcode() {
    // Start output buffering to capture form and output
    ob_start();

    // Check if the form has been submitted
    if (isset($_POST['gpt_paragraph_breaks_submit'])) {
        $text = isset($_POST['gpt_paragraph_breaks_text']) ? stripslashes($_POST['gpt_paragraph_breaks_text']) : '';
        
        // Sanitize the text input
        $text = sanitize_textarea_field($text);

        // Process the text to add paragraph breaks
        $processed_text = add_paragraph_breaks_to_text($text);

        // Display the processed text
        echo '<div id="gpt_paragraph_breaks_result">' . $processed_text . '</div>';
    }

    // Display the form for text input
    ?>
    <form method="post" action="">
        <textarea name="gpt_paragraph_breaks_text" rows="10" cols="50" placeholder="Enter your text here"></textarea><br>
        <input type="submit" name="gpt_paragraph_breaks_submit" value="Add Paragraph Breaks">
    </form>
    <?php

    // Return the output buffer content
    return ob_get_clean();
}

// Register the shortcode with WordPress
add_shortcode('gpt_paragraph_breaks', 'gpt_paragraph_breaks_shortcode');
