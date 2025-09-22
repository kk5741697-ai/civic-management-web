<?php
// +------------------------------------------------------------------------+
// | @author Aminul Islam
// | @author_url 1: http://www.vrbel.com
// | @author_email: admin@vrbel.com
// +------------------------------------------------------------------------+
// | Project Management
// | Copyright (c) 2022 Vrbel. All rights reserved.
// +------------------------------------------------------------------------+
function detect_keywords($message) {
    // Convert multi-line message to a single line by replacing line breaks with spaces
    $message = preg_replace('/\s+/', ' ', $message);

    // Check for "Full Name" using its own pattern
    if (preg_match('/full name:\s*([a-zA-Z\s]+)/i', $message, $name_match)) {
        $keywords['full_name'] = trim($name_match[1]);
        $keywords['full_name'] = str_replace('phone number', '', strtolower($keywords['full_name']));
    }

    // Check for "Job Title" using its own pattern
    if (preg_match('/job title:\s*([a-zA-Z\s]+)/i', $message, $title_match)) {
        $keywords['job_title'] = trim($title_match[1]);
    }
    
    // Check for "Company name" using its own pattern
    if (preg_match('/company name:\s*([a-zA-Z\s]+)/i', $message, $title_match)) {
        $keywords['company_title'] = trim($title_match[1]);
    }

    // If the message contains a valid phone number, classify it as "lead"
    if (!empty($keywords['full_name']) || !empty($keywords['company_name']) || !empty($keywords['job_title'])) {
        $keywords['type'] = 'lead';
    }

    return $keywords;
}

// Function to process events
function processEvent($action, $eventData) {
    switch ($action) {
        case 'messaging_postbacks':
            handleMessagingPostbacks($eventData);
            break;
        case 'leadgen':
            handleLeadgen($eventData);
            break;
        case 'messages':
            handleNewMessage($eventData);
            break;
        case 'message_reads':
            handleMessageReads($eventData);
            break;
        case 'comments':
            handleNewComments($eventData);
            break;
        default:
            file_put_contents('debug.log', "Unknown action: $action\n", FILE_APPEND);
            break;
    }
}

// Function to remove emojis from the text
function remove_emojis($text) {
    // Remove emojis using a regular expression
    return preg_replace('/[\x{1F600}-\x{1F64F}\x{1F300}-\x{1F5FF}\x{1F680}-\x{1F6FF}\x{1F700}-\x{1F77F}\x{1F780}-\x{1F7FF}\x{1F800}-\x{1F8FF}\x{1F900}-\x{1F9FF}\x{1FA00}-\x{1FA6F}\x{1FA70}-\x{1FAFF}\x{2600}-\x{26FF}\x{2700}-\x{27BF}\x{2300}-\x{23FF}\x{2B50}\x{2194}-\x{21AA}\x{25AA}\x{2B06}\x{2B07}\x{2934}\x{2935}\x{25FE}\x{1F004}\x{1F0CF}\x{2B06}\x{2934}\x{2B05}\x{1F1E6}-\x{1F1FF}\x{1F004}\x{1F0CF}\x{1F9B0}-\x{1F9B9}\x{3030}\x{260E}\x{231A}\x{1F4C5}\x{2B50}\x{2705}\x{26A0}-\x{1F6B9}\x{203C}\x{2B06}\x{2702}]/u', '', $text);
}
function get_reply_text($comment_text, $threshold = 40) {
    // Load responses from the JSON file
    $json_file_path = './comment_template.json';
    if (!file_exists($json_file_path)) {
        return [
            'comment_reply' => '.',
            'private_message' => '',
            'reaction' => 'like️'
        ];
    }

    // Decode the JSON file into an associative array
    $data = json_decode(file_get_contents($json_file_path), true);

    if (!isset($data['responses']) || !is_array($data['responses'])) {
        return [
            'comment_reply' => '',
            'private_message' => '',
            'reaction' => 'like️'
        ];
    }

    // Normalize the comment text for better matching
    $normalized_text = strtolower(trim($comment_text)); // Convert to lowercase and trim whitespace
    $emoji_removed_text = remove_emojis($normalized_text); // Remove emojis for matching

    // Initialize an array to store all similarity matches
    $matches = [];

    // Iterate over the predefined responses to find the closest match
    foreach ($data['responses'] as $response) {
        foreach ($response['keywords'] as $keyword) {
            // Normalize the keyword for comparison
            $normalized_keyword = strtolower(trim($keyword));
            // Remove emojis from the keyword as well
            $emoji_removed_keyword = remove_emojis($normalized_keyword);

            // Calculate the similarity percentage using similar_text
            similar_text($emoji_removed_text, $emoji_removed_keyword, $similarity);

            // Only add matches that meet the threshold
            if ($similarity >= $threshold) {
                // Store the match with its similarity score
                $matches[] = [
                    'response' => $response,
                    'similarity' => $similarity
                ];
            }
        }
    }

    // If no matches found that meet the threshold, return a default response
    if (empty($matches)) {
        return [
            'comment_reply' => '',
            'private_message' => '',
            'reaction' => 'like️',
        ];
    }

    // Sort matches by similarity score in descending order
    usort($matches, function ($a, $b) {
        return $b['similarity'] - $a['similarity'];
    });

    // Return the best match (the first in the sorted array)
    return $matches[0]['response'];
}

function loadMenuData() {
	global $domain_details;
	
    return include $domain_details['menu_file'];
}
function sanitize_output($buffer) {
    $search  = array(
        '/\>[^\S ]+/s', // strip whitespaces after tags, except space
        '/[^\S ]+\</s', // strip whitespaces before tags, except space
        '/(\s)+/s', // shorten multiple whitespace sequences
        '/<!--(.|\s)*?-->/'
        // Remove HTML comments
    );
    $replace = array(
        '>',
        '<',
        '\\1',
        ''
    );
    $buffer  = preg_replace($search, $replace, $buffer);
    return $buffer;
}

function Wo_CleanAllCache() {
    global $wo, $sqlConnect;
	foreach ($wo['cache_pages'] as $page) {
		$file_path = './cache/' . $page . '.tpl';
		if (file_exists($file_path)) {
			unlink($file_path);
		}
	}
	
	$value = time() + 3600;
	$query_one   = " UPDATE " . T_CONFIG . " SET `value` = '{$value}' WHERE `name` = 'cache_cleared'";
	
    $query       = mysqli_query($sqlConnect, $query_one);
	if ($query) {
        return true;
    } else {
        return false;
    }
}

function removeHtmlComments($html) {
    // Remove HTML comments
    $html = preg_replace('/<!--(.*?)-->/s', '', $html);
    return $html;
}

function removeScriptAndStyleComments($html) {
    // Remove JavaScript comments and preserve URLs within <script> tags
    $html = preg_replace_callback('/<script(.*?)>(.*?)<\/script>/is', function ($matches) {
        $scriptContent = preg_replace('/(?<=[^\:])\/\/[^\n\r]*(?:(?=\n)|(?=\r)|$)|\/\*(.*?)\*\//s', '', $matches[2]);
        return "<script{$matches[1]}>{$scriptContent}</script>";
    }, $html);

    // Remove CSS comments within <style> tags
    $html = preg_replace_callback('/<style(.*?)>(.*?)<\/style>/is', function ($matches) {
        $styleContent = preg_replace('/\/\*(.*?)\*\//s', '', $matches[2]);
        return "<style{$matches[1]}>{$styleContent}</style>";
    }, $html);

    $html = preg_replace_callback('/<\?php(.*?)\?>/is', function ($matches) {
        // Preserve URLs that start with "http://", "https://", or "://"
        $phpContent = preg_replace_callback('/(https?:\/\/[^\s"\']+|:\/\/[^\s"\']+)|\/\/[^\n\r]+|\/\*(.*?)\*\//s', function($innerMatches) {
            return isset($innerMatches[1]) ? $innerMatches[1] : '';
        }, $matches[1]);
        
        return "<?php{$phpContent}?>";
    }, $html);
    return $html;
}

function Wo_LoadPage($page_url = '', $array = []) {
    global $wo, $db, $domain_details;
    $create_file = false;

    if ($wo['config']['is_ls'] == 1) {
        foreach ($wo['cache_pages'] as $page) {
            if ($page_url == $page) {
                $parts = explode('/', $page_url);
                $first_part = $parts[0];

                if (!file_exists('cache/' . $first_part)) {
                    $oldmask = umask(0);
                    @mkdir('cache/' . $first_part, 0777, true);
                    @umask($oldmask);
                }

                $file_path = './cache/' . $page . '.tpl';
                if (file_exists($file_path)) {
                    $get_file = file_get_contents($file_path);
                    if (!empty($get_file)) {
                        return $get_file;
                    }
                } else {
                    $create_file = true;
                }
            }
        }
    } else {
        Wo_CleanAllCache();
    }

    $page      = './themes/' . $wo['config']['theme'] . '/layout/' . $page_url . '.phtml';
    $page_min  = './themes/' . $wo['config']['theme'] . '/layout/' . $page_url . '.min.phtml';

    if ($wo['config']['is_ls'] == 1) {
        if (!file_exists($page_min)) {
            $fileContents = file_get_contents($page);
            $fileContents = removeScriptAndStyleComments($fileContents);
            $fileContents = removeHtmlComments($fileContents);
            $fileContents = str_replace(["\r", "\n", "\r\n"], ' ', $fileContents);
            $fileContents = preg_replace('/\s+/', ' ', $fileContents);

            file_put_contents($page_min, $fileContents);
            $page_output = $page_min;
        } else {
            $page_output = $page_min;
        }
    } else {
        if (file_exists($page_min)) {
            unlink($page_min);
        }
        $page_output = $page;
    }

    $page_content = '';

    // 👇 Make $array values available inside the template
    if (!empty($array) && is_array($array)) {
        extract($array);
    }

    ob_start();
    require($page_output);
    $page_content = ob_get_contents();
    ob_end_clean();

    if ($create_file == true) {
        file_put_contents($file_path, $page_content);
    }

    return $page_content;
}

function Wo_CleanCache($user_id = '', $where = 'sidebar') {
    global $wo;
    if ($wo['config']['cache_sidebar'] == 0 || $wo['loggedin'] == false) {
        return false;
    }
    $file_path = './cache/sidebar-' . $wo['user']['user_id'] . '.tpl';
    if (file_exists($file_path)) {
        unlink($file_path);
    }
}

function Wo_CustomCode($a = false, $code = array()) {
    global $wo;
    $theme       = $wo['config']['theme'];
    $data        = array();
    $result      = false;
    $custom_code = array(
        "themes/$theme/custom/js/head.js",
        "themes/$theme/custom/js/footer.js",
        "themes/$theme/custom/css/style.css"
    );
    if ($a == 'g') {
        foreach ($custom_code as $key => $filepath) {
            if (is_readable($filepath)) {
                $data[$key] = file_get_contents($filepath);
            }
        }
        $result = $data;
    } else if ($a == 'p' && !empty($code)) {
        foreach ($code as $key => $content) {
            if (is_writable($custom_code[$key])) {
                @file_put_contents($custom_code[$key], base64_decode($content));
            }
        }
        $result = true;
    }
    return $result;
}
function Wo_LoadAdminPage($page_url = '') {
    global $wo, $db, $domain_details;
    $page         = './admin-panel/pages/' . $page_url . '.phtml';
    $page_content = '';
    ob_start();
    require($page);
    $page_content = ob_get_contents();
    ob_end_clean();
    return $page_content;
}
function Wo_LoadManagePage($page_url = '', $data = array()) {
    global $wo, $db, $domain_details;
    $create_file = false;

    $page     = './manage/pages/' . $page_url . '.phtml';
    $page_min = './manage/pages/' . $page_url . '.min.phtml';

    if (!file_exists($page)) {
        return ''; // page missing: return empty string to avoid fatal require
    }

    if ($wo['config']['is_ls'] == 1) {
        if (!file_exists($page_min)) {
            $fileContents = file_get_contents($page);
            if ($fileContents === false) {
                // fallback to original page if read fails
                $page_output = $page;
            } else {
                $fileContents = removeScriptAndStyleComments($fileContents);
                $fileContents = removeHtmlComments($fileContents);

                if ($page_url == 'smc') {
                    // keep as-is for smc
                } else {
                    $fileContents = str_replace(array("\r", "\n", "\r\n"), ' ', $fileContents);
                    $fileContents = preg_replace('/\s+/', ' ', $fileContents);
                }

                if (file_put_contents($page_min, $fileContents) !== false) {
                    // file created
                }
                $page_output = $page_min;
            }
        } else {
            $page_output = $page_min;
        }
    } else {
        if (file_exists($page_min)) {
            @unlink($page_min);
        }
        $page_output = $page;
    }

    // Make sure $data is an array and extract it into local scope for the included file.
    if (!is_array($data)) {
        $data = array();
    }

    // Extract with EXTR_SKIP so existing variables aren't overwritten.
    extract($data, EXTR_SKIP);

    // Capture output of the included template
    ob_start();
    require($page_output);
    $page_content = ob_get_clean();

    return $page_content;
}

function Wo_LoadAdminLinkSettings($link = '') {
    global $site_url;
    return $site_url . '/admin-cp/' . $link;
}
function Wo_LoadAdminLink($link = '') {
    global $site_url;
    return $site_url . '/admin-panel/' . $link;
}
function Wo_LoadManageLinkSettings($link = '') {
    global $site_url;
    return $site_url . '/management/' . $link;
}
function Wo_LoadManageLink($link = '') {
    global $site_url, $wo;
    return $site_url . '/manage/' . $link . '?version=' . $wo['config']['version'];
}
function Wo_SizeUnits($bytes = 0) {
    if ($bytes >= 1073741824) {
        $bytes = round(($bytes / 1073741824)) . ' GB';
    } elseif ($bytes >= 1048576) {
        $bytes = round(($bytes / 1048576)) . ' MB';
    } elseif ($bytes >= 1024) {
        $bytes = round(($bytes / 1024)) . ' KB';
    }
    return $bytes;
}
function Wo_MultipleArrayFiles($file_post) {
    if (!is_array($file_post)) {
        return array();
    }
    $wo_file_array = array();
    $wo_file_count = count($file_post['name']);
    $wo_file_keys  = array_keys($file_post);
    for ($i = 0; $i < $wo_file_count; $i++) {
        foreach ($wo_file_keys as $key) {
            $wo_file_array[$i][$key] = $file_post[$key][$i];
        }
    }
    return $wo_file_array;
}
function Wo_IsValidMimeType($mimeTypes = array()) {
    if (!is_array($mimeTypes) || empty($mimeTypes)) {
        return false;
    }
    $result = true;
    foreach ($mimeTypes as $value) {
        $type = explode('/', $value);
        if ($type[0] != 'image' && $type[0] != 'video') {
            $result = false;
            break;
        }
    }
    return $result;
}
function url_slug($str, $options = array()) {
    // Make sure string is in UTF-8 and strip invalid UTF-8 characters
    $str      = mb_convert_encoding((string) $str, 'UTF-8', mb_list_encodings());
    $defaults = array(
        'delimiter' => '-',
        'limit' => null,
        'lowercase' => true,
        'replacements' => array(),
        'transliterate' => true
    );
    // Merge options
    $options  = array_merge($defaults, $options);
    $char_map = array(
        // Latin
        'À' => 'A',
        'Á' => 'A',
        'Â' => 'A',
        'Ã' => 'A',
        'Ä' => 'A',
        'Å' => 'A',
        'Æ' => 'AE',
        'Ç' => 'C',
        'È' => 'E',
        'É' => 'E',
        'Ê' => 'E',
        'Ë' => 'E',
        'Ì' => 'I',
        'Í' => 'I',
        'Î' => 'I',
        'Ï' => 'I',
        'Ð' => 'D',
        'Ñ' => 'N',
        'Ò' => 'O',
        'Ó' => 'O',
        'Ô' => 'O',
        'Õ' => 'O',
        'Ö' => 'O',
        'Ő' => 'O',
        'Ø' => 'O',
        'Ù' => 'U',
        'Ú' => 'U',
        'Û' => 'U',
        'Ü' => 'U',
        'Ű' => 'U',
        'Ý' => 'Y',
        'Þ' => 'TH',
        'ß' => 'ss',
        'à' => 'a',
        'á' => 'a',
        'â' => 'a',
        'ã' => 'a',
        'ä' => 'a',
        'å' => 'a',
        'æ' => 'ae',
        'ç' => 'c',
        'è' => 'e',
        'é' => 'e',
        'ê' => 'e',
        'ë' => 'e',
        'ì' => 'i',
        'í' => 'i',
        'î' => 'i',
        'ï' => 'i',
        'ð' => 'd',
        'ñ' => 'n',
        'ò' => 'o',
        'ó' => 'o',
        'ô' => 'o',
        'õ' => 'o',
        'ö' => 'o',
        'ő' => 'o',
        'ø' => 'o',
        'ù' => 'u',
        'ú' => 'u',
        'û' => 'u',
        'ü' => 'u',
        'ű' => 'u',
        'ý' => 'y',
        'þ' => 'th',
        'ÿ' => 'y',
        // Latin symbols
        '©' => '(c)',
        // Greek
        'Α' => 'A',
        'Β' => 'B',
        'Γ' => 'G',
        'Δ' => 'D',
        'Ε' => 'E',
        'Ζ' => 'Z',
        'Η' => 'H',
        'Θ' => '8',
        'Ι' => 'I',
        'Κ' => 'K',
        'Λ' => 'L',
        'Μ' => 'M',
        'Ν' => 'N',
        'Ξ' => '3',
        'Ο' => 'O',
        'Π' => 'P',
        'Ρ' => 'R',
        'Σ' => 'S',
        'Τ' => 'T',
        'Υ' => 'Y',
        'Φ' => 'F',
        'Χ' => 'X',
        'Ψ' => 'PS',
        'Ω' => 'W',
        'Ά' => 'A',
        'Έ' => 'E',
        'Ί' => 'I',
        'Ό' => 'O',
        'Ύ' => 'Y',
        'Ή' => 'H',
        'Ώ' => 'W',
        'Ϊ' => 'I',
        'Ϋ' => 'Y',
        'α' => 'a',
        'β' => 'b',
        'γ' => 'g',
        'δ' => 'd',
        'ε' => 'e',
        'ζ' => 'z',
        'η' => 'h',
        'θ' => '8',
        'ι' => 'i',
        'κ' => 'k',
        'λ' => 'l',
        'μ' => 'm',
        'ν' => 'n',
        'ξ' => '3',
        'ο' => 'o',
        'π' => 'p',
        'ρ' => 'r',
        'σ' => 's',
        'τ' => 't',
        'υ' => 'y',
        'φ' => 'f',
        'χ' => 'x',
        'ψ' => 'ps',
        'ω' => 'w',
        'ά' => 'a',
        'έ' => 'e',
        'ί' => 'i',
        'ό' => 'o',
        'ύ' => 'y',
        'ή' => 'h',
        'ώ' => 'w',
        'ς' => 's',
        'ϊ' => 'i',
        'ΰ' => 'y',
        'ϋ' => 'y',
        'ΐ' => 'i',
        // Turkish
        'Ş' => 'S',
        'İ' => 'I',
        'Ç' => 'C',
        'Ü' => 'U',
        'Ö' => 'O',
        'Ğ' => 'G',
        'ş' => 's',
        'ı' => 'i',
        'ç' => 'c',
        'ü' => 'u',
        'ö' => 'o',
        'ğ' => 'g',
        // Russian
        'А' => 'A',
        'Б' => 'B',
        'В' => 'V',
        'Г' => 'G',
        'Д' => 'D',
        'Е' => 'E',
        'Ё' => 'Yo',
        'Ж' => 'Zh',
        'З' => 'Z',
        'И' => 'I',
        'Й' => 'J',
        'К' => 'K',
        'Л' => 'L',
        'М' => 'M',
        'Н' => 'N',
        'О' => 'O',
        'П' => 'P',
        'Р' => 'R',
        'С' => 'S',
        'Т' => 'T',
        'У' => 'U',
        'Ф' => 'F',
        'Х' => 'H',
        'Ц' => 'C',
        'Ч' => 'Ch',
        'Ш' => 'Sh',
        'Щ' => 'Sh',
        'Ъ' => '',
        'Ы' => 'Y',
        'Ь' => '',
        'Э' => 'E',
        'Ю' => 'Yu',
        'Я' => 'Ya',
        'а' => 'a',
        'б' => 'b',
        'в' => 'v',
        'г' => 'g',
        'д' => 'd',
        'е' => 'e',
        'ё' => 'yo',
        'ж' => 'zh',
        'з' => 'z',
        'и' => 'i',
        'й' => 'j',
        'к' => 'k',
        'л' => 'l',
        'м' => 'm',
        'н' => 'n',
        'о' => 'o',
        'п' => 'p',
        'р' => 'r',
        'с' => 's',
        'т' => 't',
        'у' => 'u',
        'ф' => 'f',
        'х' => 'h',
        'ц' => 'c',
        'ч' => 'ch',
        'ш' => 'sh',
        'щ' => 'sh',
        'ъ' => '',
        'ы' => 'y',
        'ь' => '',
        'э' => 'e',
        'ю' => 'yu',
        'я' => 'ya',
        // Ukrainian
        'Є' => 'Ye',
        'І' => 'I',
        'Ї' => 'Yi',
        'Ґ' => 'G',
        'є' => 'ye',
        'і' => 'i',
        'ї' => 'yi',
        'ґ' => 'g',
        // Czech
        'Č' => 'C',
        'Ď' => 'D',
        'Ě' => 'E',
        'Ň' => 'N',
        'Ř' => 'R',
        'Š' => 'S',
        'Ť' => 'T',
        'Ů' => 'U',
        'Ž' => 'Z',
        'č' => 'c',
        'ď' => 'd',
        'ě' => 'e',
        'ň' => 'n',
        'ř' => 'r',
        'š' => 's',
        'ť' => 't',
        'ů' => 'u',
        'ž' => 'z',
        // Polish
        'Ą' => 'A',
        'Ć' => 'C',
        'Ę' => 'e',
        'Ł' => 'L',
        'Ń' => 'N',
        'Ó' => 'o',
        'Ś' => 'S',
        'Ź' => 'Z',
        'Ż' => 'Z',
        'ą' => 'a',
        'ć' => 'c',
        'ę' => 'e',
        'ł' => 'l',
        'ń' => 'n',
        'ó' => 'o',
        'ś' => 's',
        'ź' => 'z',
        'ż' => 'z',
        // Latvian
        'Ā' => 'A',
        'Č' => 'C',
        'Ē' => 'E',
        'Ģ' => 'G',
        'Ī' => 'i',
        'Ķ' => 'k',
        'Ļ' => 'L',
        'Ņ' => 'N',
        'Š' => 'S',
        'Ū' => 'u',
        'Ž' => 'Z',
        'ā' => 'a',
        'č' => 'c',
        'ē' => 'e',
        'ģ' => 'g',
        'ī' => 'i',
        'ķ' => 'k',
        'ļ' => 'l',
        'ņ' => 'n',
        'š' => 's',
        'ū' => 'u',
        'ž' => 'z'
    );
    // Make custom replacements
    $str      = preg_replace(array_keys($options['replacements']), $options['replacements'], $str);
    // Transliterate characters to ASCII
    if ($options['transliterate']) {
        $str = str_replace(array_keys($char_map), $char_map, $str);
    }
    // Replace non-alphanumeric characters with our delimiter
    $str = preg_replace('/[^\p{L}\p{Nd}]+/u', $options['delimiter'], $str);
    // Remove duplicate delimiters
    $str = preg_replace('/(' . preg_quote($options['delimiter'], '/') . '){2,}/', '$1', $str);
    // Truncate slug to max. characters
    $str = mb_substr($str, 0, ($options['limit'] ? $options['limit'] : mb_strlen($str, 'UTF-8')), 'UTF-8');
    // Remove delimiter from ends
    $str = trim($str, $options['delimiter']);
    return $options['lowercase'] ? mb_strtolower($str, 'UTF-8') : $str;
}
function Wo_SeoLink($query = '') {
    global $wo, $config;
    if ($wo['config']['seoLink'] == 1) {
        $query = preg_replace(array(
            '/^index\.php\?link1=search&query=(.*)$/i',
            '/^index\.php\?link1=projects&project=(.*)$/i',
            '/^index\.php\?link1=developers&page=(.*)$/i',
            '/^index\.php\?link1=reviews&id=(.*)$/i',
            '/^index\.php\?link1=order&id=(.*)$/i',
            '/^index\.php\?link1=customer_order&id=(.*)$/i',
            '/^index\.php\?link1=edit_fund&id=([A-Za-z0-9_]+)$/i',
            '/^index\.php\?link1=show_fund&id=([A-Za-z0-9_]+)$/i',
            '/^index\.php\?link1=timeline&u=([A-Za-z0-9_]+)&type=([A-Za-z0-9_]+)&id=([A-Za-z0-9_]+)$/i',
            '/^index\.php\?link1=jobs$/i',
            '/^index\.php\?link1=forumaddthred&fid=(\d+)$/i',
            '/^index\.php\?link1=welcome&link2=password_reset&user_id=([A-Za-z0-9_]+)$/i',
            '/^index\.php\?link1=welcome&last_url=(.*)$/i',
            '/^index\.php\?link1=([^\/]+)&query=$/i',
            '/^index\.php\?link1=post&id=(.*)$/i',
            '/^index\.php\?link1=post&id=([A-Za-z0-9_]+)&ref=([A-Za-z0-9_]+)$/i',
            '/^index\.php\?link1=terms&page=contact-us$/i',
            '/^index\.php\?link1=([^\/]+)&u=([A-Za-z0-9_]+)$/i',
            '/^index\.php\?link1=timeline&u=([A-Za-z0-9_]+)&type=([A-Za-z0-9_]+)$/i',
            '/^index\.php\?link1=messages&user=([A-Za-z0-9_]+)$/i',
            '/^index\.php\?link1=setting&page=([A-Za-z0-9_-]+)$/i',
            '/^index\.php\?link1=setting&user=([A-Za-z0-9_]+)&page=([A-Za-z0-9_-]+)$/i',
            '/^index\.php\?link1=([^\/]+)&app_id=([A-Za-z0-9_]+)$/i',
            '/^index\.php\?link1=([^\/]+)&hash=([^\/]+)$/i',
            '/^index\.php\?link1=([^\/]+)&link2=([^\/]+)$/i',
            '/^index\.php\?link1=([^\/]+)&type=([^\/]+)$/i',
            '/^index\.php\?link1=([^\/]+)&p=([^\/]+)$/i',
            '/^index\.php\?link1=([^\/]+)&g=([^\/]+)$/i',
            '/^index\.php\?link1=page-setting&page=([A-Za-z0-9_]+)&link3=([A-Za-z0-9_-]+)&name=([A-Za-z0-9_-]+)$/i',
            '/^index\.php\?link1=page-setting&page=([A-Za-z0-9_]+)&link3=([A-Za-z0-9_-]+)$/i',
            '/^index\.php\?link1=page-setting&page=([^\/]+)$/i',
            '/^index\.php\?link1=group-setting&group=([A-Za-z0-9_]+)&link3=([A-Za-z0-9_-]+)&name=([A-Za-z0-9_-]+)$/i',
            '/^index\.php\?link1=group-setting&group=([A-Za-z0-9_]+)&link3=([A-Za-z0-9_-]+)$/i',
            '/^index\.php\?link1=group-setting&group=([^\/]+)$/i',
            '/^index\.php\?link1=admincp&page=([^\/]+)$/i',
            '/^index\.php\?link1=game&id=([^\/]+)$/i',
            '/^index\.php\?link1=albums&user=([A-Za-z0-9_]+)$/i',
            '/^index\.php\?link1=create-album&album=([A-Za-z0-9_]+)$/i',
            '/^index\.php\?link1=edit-product&id=([A-Za-z0-9_]+)$/i',
            '/^index\.php\?link1=products&c_id=([A-Za-z0-9_]+)$/i',
            '/^index\.php\?link1=products&c_id=([A-Za-z0-9_]+)&sub_id=([A-Za-z0-9_]+)$/i',
            '/^index\.php\?link1=site-pages&page_name=(.*)$/i',
            '/^index\.php\?link1=create-blog$/i',
            '/^index\.php\?link1=my-blogs$/i',
            '/^index\.php\?link1=forum$/i',
            '/^index\.php\?link1=forumsadd&fid=(\d+)$/i',
            '/^index\.php\?link1=forums&fid=(\d+)$/i',
            '/^index\.php\?link1=showthread&tid=(\d+)$/i',
            '/^index\.php\?link1=threadreply&tid=(\d+)$/i',
            '/^index\.php\?link1=threadquote&tid=(\d+)$/i',
            '/^index\.php\?link1=editreply&tid=(\d+)$/i',
            '/^index\.php\?link1=edithread&tid=(\d+)$/i',
            '/^index\.php\?link1=mythreads$/i',
            '/^index\.php\?link1=mymessages$/i',
            '/^index\.php\?link1=read-blog&id=([^\/]+)$/i',
            '/^index\.php\?link1=blog-category&id=([^\/]+)$/i',
            '/^index\.php\?link1=edit-blog&id=([^\/]+)$/i',
            '/^index\.php\?link1=forum-members$/i',
            '/^index\.php\?link1=forum-members-byname&char=([a-zA-Z])$/i',
            '/^index\.php\?link1=forum-search$/i',
            '/^index\.php\?link1=forum-search-result$/i',
            '/^index\.php\?link1=forum-events$/i',
            '/^index\.php\?link1=forum-help$/i',
            '/^index\.php\?link1=events$/i',
            '/^index\.php\?link1=show-event&eid=(\d+)$/i',
            '/^index\.php\?link1=create-event$/i',
            '/^index\.php\?link1=edit-event&eid=(\d+)$/i',
            '/^index\.php\?link1=events-going$/i',
            '/^index\.php\?link1=events-invited$/i',
            '/^index\.php\?link1=events-interested$/i',
            '/^index\.php\?link1=events-past$/i',
            '/^index\.php\?link1=my-events$/i',
            '/^index\.php\?link1=movies$/i',
            '/^index\.php\?link1=movies-genre&genre=([A-Za-z-]+)$/i',
            '/^index\.php\?link1=movies-country&country=([A-Za-z-]+)$/i',
            '/^index\.php\?link1=watch-film&film-id=(\d+)$/i',
            '/^index\.php\?link1=advertise$/i',
            '/^index\.php\?link1=wallet$/i',
            '/^index\.php\?link1=create-ads$/i',
            '/^index\.php\?link1=edit-ads&id=(\d+)$/i',
            '/^index\.php\?link1=chart-ads&id=(\d+)$/i',
            '/^index\.php\?link1=manage-ads&id=(\d+)$/i',
            '/^index\.php\?link1=create-status$/i',
            '/^index\.php\?link1=friends-nearby$/i',
            '/^index\.php\?link1=([^\/]+)$/i',
            '/^index\.php\?link1=welcome$/i'
        ), array(
            $config['site_url'] . '/search/$1',
            $config['site_url'] . '/projects/$1',
            $config['site_url'] . '/developers?page=$1',
            $config['site_url'] . '/reviews/$1',
            $config['site_url'] . '/order/$1',
            $config['site_url'] . '/customer_order/$1',
            $config['site_url'] . '/edit_fund/$1',
            $config['site_url'] . '/show_fund/$1',
            $config['site_url'] . '/$1/$2&id=$3',
            $config['site_url'] . '/jobs',
            $config['site_url'] . '/forums/add/$1/',
            $config['site_url'] . '/password-reset/$1',
            $config['site_url'] . '/welcome/?last_url=$1',
            $config['site_url'] . '/search/$2',
            $config['site_url'] . '/post/$1',
            $config['site_url'] . '/post/$1?ref=$2',
            $config['site_url'] . '/terms/contact-us',
            $config['site_url'] . '/$2',
            $config['site_url'] . '/$1/$2',
            $config['site_url'] . '/messages/$1',
            $config['site_url'] . '/setting/$1',
            $config['site_url'] . '/setting/$1/$2',
            $config['site_url'] . '/$1/$2',
            $config['site_url'] . '/$1/$2',
            $config['site_url'] . '/$1/$2',
            $config['site_url'] . '/$1/$2',
            $config['site_url'] . '/p/$2',
            $config['site_url'] . '/g/$2',
            $config['site_url'] . '/page-setting/$1/$2?name=$3',
            $config['site_url'] . '/page-setting/$1/$2',
            $config['site_url'] . '/page-setting/$1',
            $config['site_url'] . '/group-setting/$1/$2?name=$3',
            $config['site_url'] . '/group-setting/$1/$2',
            $config['site_url'] . '/group-setting/$1',
            $config['site_url'] . '/admincp/$1',
            $config['site_url'] . '/game/$1',
            $config['site_url'] . '/albums/$1',
            $config['site_url'] . '/create-album/$1',
            $config['site_url'] . '/edit-product/$1',
            $config['site_url'] . '/products/$1',
            $config['site_url'] . '/products/$1/$2',
            $config['site_url'] . '/site-pages/$1',
            $config['site_url'] . '/create-blog/',
            $config['site_url'] . '/my-blogs/',
            $config['site_url'] . '/forum/',
            $config['site_url'] . '/forums/add/$1/',
            $config['site_url'] . '/forums/$1/',
            $config['site_url'] . '/forums/thread/$1/',
            $config['site_url'] . '/forums/thread/reply/$1/',
            $config['site_url'] . '/forums/thread/quote/$1/',
            $config['site_url'] . '/forums/thread/edit/$1/',
            $config['site_url'] . '/forums/user/threads/edit/$1/',
            $config['site_url'] . '/forums/user/threads/',
            $config['site_url'] . '/forums/user/messages/',
            $config['site_url'] . '/read-blog/$1',
            $config['site_url'] . '/blog-category/$1',
            $config['site_url'] . '/edit-blog/$1',
            $config['site_url'] . '/forum/members/',
            $config['site_url'] . '/forum/members/$1/',
            $config['site_url'] . '/forum/search/',
            $config['site_url'] . '/forum/search-result/',
            $config['site_url'] . '/forum/events/',
            $config['site_url'] . '/forum/help/',
            $config['site_url'] . '/events/',
            $config['site_url'] . '/events/$1/',
            $config['site_url'] . '/events/create-event/',
            $config['site_url'] . '/events/edit/$1/',
            $config['site_url'] . '/events/going/',
            $config['site_url'] . '/events/invited/',
            $config['site_url'] . '/events/interested/',
            $config['site_url'] . '/events/past/',
            $config['site_url'] . '/events/my/',
            $config['site_url'] . '/movies/',
            $config['site_url'] . '/movies/genre/$1/',
            $config['site_url'] . '/movies/country/$1/',
            $config['site_url'] . '/movies/watch/$1',
            $config['site_url'] . '/advertise',
            $config['site_url'] . '/wallet/',
            $config['site_url'] . '/ads/create/',
            $config['site_url'] . '/ads/edit/$1/',
            $config['site_url'] . '/ads/chart/$1/',
            $config['site_url'] . '/admin/ads/edit/$1/',
            $config['site_url'] . '/status/create/',
            $config['site_url'] . '/friends-nearby/',
            $config['site_url'] . '/$1',
            $config['site_url']
        ), $query);
    } else {
        $query = $config['site_url'] . '/' . $query;
    }
    return $query;
}
function Wo_IsLogged() {
    if (isset($_SESSION['user_id']) && !empty($_SESSION['user_id'])) {
        $id = Wo_GetUserFromSessionID($_SESSION['user_id']);
        if (is_numeric($id) && !empty($id)) {
            return true;
        }
    } else if (!empty($_COOKIE['user_id']) && !empty($_COOKIE['user_id'])) {
        $id = Wo_GetUserFromSessionID($_COOKIE['user_id']);
        if (is_numeric($id) && !empty($id)) {
            return true;
        }
    } else {
        return false;
    }
}
function Wo_Redirect($url) {
    return header("Location: {$url}");
}
function Wo_Link($string) {
    global $site_url;
    return $site_url . '/' . $string;
}
function Wo_Sql_Result($res, $row = 0, $col = 0) {
    $numrows = mysqli_num_rows($res);
    if ($numrows && $row <= ($numrows - 1) && $row >= 0) {
        mysqli_data_seek($res, $row);
        $resrow = (is_numeric($col)) ? mysqli_fetch_row($res) : mysqli_fetch_assoc($res);
        if (isset($resrow[$col])) {
            return $resrow[$col];
        }
    }
    return false;
}
function Wo_UrlDomain($url) {
    $host = @parse_url($url, PHP_URL_HOST);
    if (!$host) {
        $host = $url;
    }
    if (substr($host, 0, 4) == "www.") {
        $host = substr($host, 4);
    }
    if (strlen($host) > 50) {
        $host = substr($host, 0, 47) . '...';
    }
    return $host;
}
function Wo_Secure($string, $censored_words = 0, $br = true, $strip = 0,$cleanString = true) {
    global $sqlConnect;
    $string = trim($string);
    if ($cleanString) {
        $string = cleanString($string);
    }
    $string = mysqli_real_escape_string($sqlConnect, $string);
    $string = htmlspecialchars($string, ENT_QUOTES);
    if ($br == true) {
        $string = str_replace('\r\n', " <br>", $string);
        $string = str_replace('\n\r', " <br>", $string);
        $string = str_replace('\r', " <br>", $string);
        $string = str_replace('\n', " <br>", $string);
    } else {
        $string = str_replace('\r\n', "", $string);
        $string = str_replace('\n\r', "", $string);
        $string = str_replace('\r', "", $string);
        $string = str_replace('\n', "", $string);
    }
    if ($strip == 1) {
        $string = stripslashes($string);
    }
    $string = str_replace('&amp;#', '&#', $string);
    if ($censored_words == 1) {
        global $config;
        $censored_words = @explode(",", $config['censored_words']);
        foreach ($censored_words as $censored_word) {
            $censored_word = trim($censored_word);
            $string        = str_replace($censored_word, '****', $string);
        }
    }
    return $string;
}
function Wo_BbcodeSecure($string) {
    global $sqlConnect;
    $string = trim($string);
    $string = mysqli_real_escape_string($sqlConnect, $string);
    $string = htmlspecialchars($string, ENT_QUOTES);
    $string = str_replace('\r\n', "[nl]", $string);
    $string = str_replace('\n\r', "[nl]", $string);
    $string = str_replace('\r', "[nl]", $string);
    $string = str_replace('\n', "[nl]", $string);
    $string = str_replace('&amp;#', '&#', $string);
    $string = strip_tags($string);
    $string = stripslashes($string);
    return $string;
}
function Wo_Decode($string) {
    return htmlspecialchars_decode($string);
}
function Wo_GenerateKey($minlength = 20, $maxlength = 20, $uselower = true, $useupper = true, $usenumbers = true, $usespecial = false) {
    $charset = '';
    if ($uselower) {
        $charset .= "abcdefghijklmnopqrstuvwxyz";
    }
    if ($useupper) {
        $charset .= "ABCDEFGHIJKLMNOPQRSTUVWXYZ";
    }
    if ($usenumbers) {
        $charset .= "123456789";
    }
    if ($usespecial) {
        $charset .= "~@#$%^*()_+-={}|][";
    }
    if ($minlength > $maxlength) {
        $length = mt_rand($maxlength, $minlength);
    } else {
        $length = mt_rand($minlength, $maxlength);
    }
    $key = '';
    for ($i = 0; $i < $length; $i++) {
        $key .= $charset[(mt_rand(0, strlen($charset) - 1))];
    }
    return $key;
}
$can = 0;
function Wo_CropAvatarImage($file = '', $data = array()) {
    global $wo;
    if (empty($file)) {
        return false;
    }
    if (!isset($data['x']) || !isset($data['y']) || !isset($data['w']) || !isset($data['h'])) {
        return false;
    }
    if (!file_exists($file)) {
        $get_media = file_put_contents($file, file_get_contents(Wo_GetMedia($file)));
    }
    if (!file_exists($file)) {
        return false;
    }
    $imgsize = @getimagesize($file);
    if (empty($imgsize)) {
        return false;
    }
    $width    = $data['w'];
    $height   = $data['h'];
    $source_x = $data['x'];
    $source_y = $data['y'];
    $mime     = $imgsize['mime'];
    $image    = "imagejpeg";
    switch ($mime) {
        case 'image/gif':
            $image_create = "imagecreatefromgif";
            break;
        case 'image/png':
            $image_create = "imagecreatefrompng";
            break;
        case 'image/jpeg':
            $image_create = "imagecreatefromjpeg";
            break;
        default:
            return false;
            break;
    }
    $dest = imagecreatetruecolor($width, $height);
    $src  = $image_create($file);
    $file = str_replace('_full', '', $file);
    imagecopy($dest, $src, 30, 30, $source_x, $source_y, $width, $height);
    $to_crop_array = array(
        'x' => $source_x,
        'y' => $source_y,
        'width' => $width,
        'height' => $height
    );
    $dest          = imagecrop($src, $to_crop_array);
    imagejpeg($dest, $file, 100);
    Wo_Resize_Crop_Image($wo['profile_picture_width_crop'], $wo['profile_picture_height_crop'], $file, $file, $wo['config']['images_quality']);
    $s3 = Wo_UploadToS3($file);
    return true;
}
function Wo_Resize_Crop_Image($max_width, $max_height, $source_file, $dst_dir, $quality = 80) {
    $imgsize = @getimagesize($source_file);
    $width   = $imgsize[0];
    $height  = $imgsize[1];
    $mime    = $imgsize['mime'];
    $image   = "imagejpeg";
    switch ($mime) {
        case 'image/gif':
            $image_create = "imagecreatefromgif";
            break;
        case 'image/png':
            $image_create = "imagecreatefrompng";
            break;
        case 'image/jpeg':
            $image_create = "imagecreatefromjpeg";
            break;
        case 'image/webp':
            $image_create = "imagecreatefromwebp";
            break;
        default:
            return false;
            break;
    }
    $dst_img = @imagecreatetruecolor($max_width, $max_height);
    $src_img = @$image_create($source_file);
    if (function_exists('exif_read_data')) {
        $exif          = @exif_read_data($source_file);
        $another_image = false;
        if (!empty($exif['Orientation'])) {
            switch ($exif['Orientation']) {
                case 3:
                    $src_img = @imagerotate($src_img, 180, 0);
                    @imagejpeg($src_img, $dst_dir, $quality);
                    $another_image = true;
                    break;
                case 6:
                    $src_img = @imagerotate($src_img, -90, 0);
                    @imagejpeg($src_img, $dst_dir, $quality);
                    $another_image = true;
                    break;
                case 8:
                    $src_img = @imagerotate($src_img, 90, 0);
                    @imagejpeg($src_img, $dst_dir, $quality);
                    $another_image = true;
                    break;
            }
        }
        if ($another_image == true) {
            $imgsize = @getimagesize($dst_dir);
            if ($width > 0 && $height > 0) {
                $width  = $imgsize[0];
                $height = $imgsize[1];
            }
        }
    }
    @$width_new = $height * $max_width / $max_height;
    @$height_new = $width * $max_height / $max_width;
    if ($width_new > $width) {
        $h_point = (($height - $height_new) / 2);
        @imagecopyresampled($dst_img, $src_img, 0, 0, 0, $h_point, $max_width, $max_height, $width, $height_new);
    } else {
        $w_point = (($width - $width_new) / 2);
        @imagecopyresampled($dst_img, $src_img, 0, 0, $w_point, 0, $max_width, $max_height, $width_new, $height);
    }
    @imagejpeg($dst_img, $dst_dir, $quality);
    if ($dst_img)
        @imagedestroy($dst_img);
    if ($src_img)
        @imagedestroy($src_img);
    return true;
}
function str_replace_first($search, $replace, $subject) {
    $pos = strpos($subject, $search);
    if ($pos !== false) {
        return substr_replace($subject, $replace, $pos, strlen($search));
    }
    return $subject;
}
function substitute($stringOrFunction, $number) {
    //$string = $stringOrFunction;
    return $number . ' ' . $stringOrFunction;
}
function Wo_Time_Elapsed_String_word($ptime)
{
    global $wo;
    $etime = time() - $ptime;
    if ($etime < 1) {
        return '0 seconds';
    }
    $a        = array(
        365 * 24 * 60 * 60 => $wo['lang']['year'],
        30 * 24 * 60 * 60 => $wo['lang']['month'],
        24 * 60 * 60 => $wo['lang']['day'],
        60 * 60 => $wo['lang']['hour'],
        60 => $wo['lang']['minute'],
        1 => $wo['lang']['second']
    );
    $a_plural = array(
        $wo['lang']['year'] => $wo['lang']['years'],
        $wo['lang']['month'] => $wo['lang']['months'],
        $wo['lang']['day'] => $wo['lang']['days'],
        $wo['lang']['hour'] => $wo['lang']['hours'],
        $wo['lang']['minute'] => $wo['lang']['minutes'],
        $wo['lang']['second'] => $wo['lang']['seconds']
    );
    foreach ($a as $secs => $str) {
        $d = $etime / $secs;
        if ($d >= 1) {
            $r = round($d);
            if ($wo['language_type'] == 'rtl') {
                $time_ago = $wo['lang']['time_ago'] . ' ' . $r . ' ' . ($r > 1 ? $a_plural[$str] : $str);
            } else {
                $time_ago = $r . ' ' . ($r > 1 ? $a_plural[$str] : $str) . ' ' . $wo['lang']['time_ago'];
            }
            return $time_ago;
        }
    }
}
function Wo_Time_Elapsed_String($ptime) {
    global $wo;
    $etime = (time()) - $ptime;
    if ($etime < 1) {
        //return '0 seconds';
        return 'Now';
    }
    $seconds = abs($etime);
    $minutes = $seconds / 60;
    $hours   = $minutes / 60;
    $days    = $hours / 24;
    $weeks   = $days / 7;
    $years   = $days / 365;
    if ($seconds < 45) {
        return substitute($wo['lang']['now'], '');
    } elseif ($seconds < 90) {
        return substitute($wo['lang']['_time_m'], 1);
    } elseif ($minutes < 45) {
        return substitute($wo['lang']['_time_m'], round($minutes));
    } elseif ($minutes < 90) {
        return substitute($wo['lang']['_time_h'], 1);
    } elseif ($hours < 24) {
        return substitute($wo['lang']['_time_hrs'], round($hours));
    } elseif ($hours < 42) {
        return substitute($wo['lang']['_time_d'], 1);
    } elseif ($days < 7) {
        return substitute($wo['lang']['_time_d'], round($days));
    } elseif ($weeks < 2) {
        return substitute($wo['lang']['_time_w'], 1);
    } elseif ($weeks < 52) {
        return substitute($wo['lang']['_time_w'], round($weeks));
    } elseif ($years < 1.5) {
        return substitute($wo['lang']['_time_y'], 1);
    } else {
        return substitute($wo['lang']['_time_yrs'], round($years));
    }
    // $a        = array(
    //     365 * 24 * 60 * 60 => $wo['lang']['year'],
    //     30 * 24 * 60 * 60 => $wo['lang']['month'],
    //     24 * 60 * 60 => $wo['lang']['day'],
    //     60 * 60 => $wo['lang']['hour'],
    //     60 => $wo['lang']['minute'],
    //     1 => $wo['lang']['second']
    // );
    // $a_plural = array(
    //     $wo['lang']['year'] => $wo['lang']['years'],
    //     $wo['lang']['month'] => $wo['lang']['months'],
    //     $wo['lang']['day'] => $wo['lang']['days'],
    //     $wo['lang']['hour'] => $wo['lang']['hours'],
    //     $wo['lang']['minute'] => $wo['lang']['minutes'],
    //     $wo['lang']['second'] => $wo['lang']['seconds']
    // );
    // foreach ($a as $secs => $str) {
    //     $d = $etime / $secs;
    //     if ($d >= 1) {
    //         $r = round($d);
    //         if ($wo['language_type'] == 'rtl') {
    //             //$time_ago = $wo['lang']['time_ago'] . ' ' . $r . ' ' . ($r > 1 ? $a_plural[$str] : $str);
    //             if ($secs > 1) {
    //                 $time_ago = $r . ' ' . ($r > 1 ? $a_plural[$str] : $str);
    //             }
    //             else{
    //                 $time_ago = $wo['lang']['now'];
    //             }
    //         } else {
    //             //$time_ago = $r . ' ' . ($r > 1 ? $a_plural[$str] : $str) . ' ' . $wo['lang']['time_ago'];
    //             if ($secs > 1) {
    //                 $time_ago = $r . ' ' . ($r > 1 ? $a_plural[$str] : $str);
    //             }
    //             else{
    //                 $time_ago = $wo['lang']['now'];
    //             }
    //         }
    //         return $time_ago;
    //     }
    // }
}
function Wo_FolderSize($dir) {
    $count_size = 0;
    $count      = 0;
    $dir_array  = scandir($dir);
    foreach ($dir_array as $key => $filename) {
        if ($filename != ".." && $filename != "." && $filename != ".htaccess") {
            if (is_dir($dir . "/" . $filename)) {
                $new_foldersize = Wo_FolderSize($dir . "/" . $filename);
                $count_size     = $count_size + $new_foldersize;
            } else if (is_file($dir . "/" . $filename)) {
                $count_size = $count_size + filesize($dir . "/" . $filename);
                $count++;
            }
        }
    }
    return $count_size;
}
function Wo_SizeFormat($bytes) {
    $kb = 1024;
    $mb = $kb * 1024;
    $gb = $mb * 1024;
    $tb = $gb * 1024;
    if (($bytes >= 0) && ($bytes < $kb)) {
        return $bytes . ' B';
    } elseif (($bytes >= $kb) && ($bytes < $mb)) {
        return ceil($bytes / $kb) . ' KB';
    } elseif (($bytes >= $mb) && ($bytes < $gb)) {
        return ceil($bytes / $mb) . ' MB';
    } elseif (($bytes >= $gb) && ($bytes < $tb)) {
        return ceil($bytes / $gb) . ' GB';
    } elseif ($bytes >= $tb) {
        return ceil($bytes / $tb) . ' TB';
    } else {
        return $bytes . ' B';
    }
}
function Wo_ClearCache() {
    $path = 'cache';
    if ($handle = opendir($path)) {
        while (false !== ($file = readdir($handle))) {
            if (strripos($file, '.tmp') !== false) {
                @unlink($path . '/' . $file);
            }
        }
    }
}
function Wo_GetThemes() {
    global $wo;
    $themes = glob('themes/*', GLOB_ONLYDIR);
    return $themes;
}
function Wo_ReturnBytes($val) {
    $val  = trim($val);
    $last = strtolower($val[strlen($val) - 1]);
    switch ($last) {
        case 'g':
            $val *= 1024;
        case 'm':
            $val *= 1024;
        case 'k':
            $val *= 1024;
    }
    return $val;
}
function getBaseUrl() {
    $currentPath = $_SERVER['PHP_SELF'];
    $pathInfo    = pathinfo($currentPath);
    $hostName    = $_SERVER['HTTP_HOST'];
    return $hostName . $pathInfo['dirname'];
}
function Wo_MaxFileUpload() {
    //select maximum upload size
    $max_upload   = Wo_ReturnBytes(ini_get('upload_max_filesize'));
    //select post limit
    $max_post     = Wo_ReturnBytes(ini_get('post_max_size'));
    //select memory limit
    $memory_limit = Wo_ReturnBytes(ini_get('memory_limit'));
    // return the smallest of them, this defines the real limit
    return min($max_upload, $max_post, $memory_limit);
}
function Wo_CompressImage($source_url, $destination_url, $quality = 50) {
    $imgsize = getimagesize($source_url);
    $finfof  = $imgsize['mime'];
    $image_c = 'imagejpeg';
    if ($finfof == 'image/jpeg') {
        $image = @imagecreatefromjpeg($source_url);
    } else if ($finfof == 'image/gif') {
        $image = @imagecreatefromgif($source_url);
    } else if ($finfof == 'image/png') {
        $image = @imagecreatefrompng($source_url);
    } else if ($finfof == 'image/webp') {
        $image = @imagecreatefromwebp($source_url);
    } else {
        $image = @imagecreatefromjpeg($source_url);
    }
    if (function_exists('exif_read_data')) {
        $exif = @exif_read_data($source_url);
        if (!empty($exif['Orientation'])) {
            switch ($exif['Orientation']) {
                case 3:
                    $image = @imagerotate($image, 180, 0);
                    break;
                case 6:
                    $image = @imagerotate($image, -90, 0);
                    break;
                case 8:
                    $image = @imagerotate($image, 90, 0);
                    break;
            }
        }
    }
    @imagejpeg($image, $destination_url, $quality);
    return $destination_url;
}
function get_ip_address() {
    if (!empty($_SERVER['HTTP_X_FORWARDED']) && validate_ip($_SERVER['HTTP_X_FORWARDED']))
        return $_SERVER['HTTP_X_FORWARDED'];
    if (!empty($_SERVER['HTTP_X_CLUSTER_CLIENT_IP']) && validate_ip($_SERVER['HTTP_X_CLUSTER_CLIENT_IP']))
        return $_SERVER['HTTP_X_CLUSTER_CLIENT_IP'];
    if (!empty($_SERVER['HTTP_FORWARDED_FOR']) && validate_ip($_SERVER['HTTP_FORWARDED_FOR']))
        return $_SERVER['HTTP_FORWARDED_FOR'];
    if (!empty($_SERVER['HTTP_FORWARDED']) && validate_ip($_SERVER['HTTP_FORWARDED']))
        return $_SERVER['HTTP_FORWARDED'];
    return $_SERVER['REMOTE_ADDR'];
}
function validate_ip($ip) {
    if (strtolower($ip) === 'unknown')
        return false;
    $ip = ip2long($ip);
    if ($ip !== false && $ip !== -1) {
        $ip = sprintf('%u', $ip);
        if ($ip >= 0 && $ip <= 50331647)
            return false;
        if ($ip >= 167772160 && $ip <= 184549375)
            return false;
        if ($ip >= 2130706432 && $ip <= 2147483647)
            return false;
        if ($ip >= 2851995648 && $ip <= 2852061183)
            return false;
        if ($ip >= 2886729728 && $ip <= 2887778303)
            return false;
        if ($ip >= 3221225984 && $ip <= 3221226239)
            return false;
        if ($ip >= 3232235520 && $ip <= 3232301055)
            return false;
        if ($ip >= 4294967040)
            return false;
    }
    return true;
}
function Wo_Backup($sql_db_host, $sql_db_user, $sql_db_pass, $sql_db_name, $tables = false, $backup_name = false) {
    $mysqli = new mysqli($sql_db_host, $sql_db_user, $sql_db_pass, $sql_db_name);
    $mysqli->select_db($sql_db_name);
    $mysqli->query("SET NAMES 'utf8'");
    $queryTables = $mysqli->query('SHOW TABLES');
    while ($row = $queryTables->fetch_row()) {
        $target_tables[] = $row[0];
    }
    if ($tables !== false) {
        $target_tables = array_intersect($target_tables, $tables);
    }
    $content = "-- phpMyAdmin SQL Dump
-- http://www.phpmyadmin.net
--
-- Host Connection Info: " . $mysqli->host_info . "
-- Generation Time: " . date('F d, Y \a\t H:i A ( e )') . "
-- Server version: " . mysqli_get_server_info($mysqli) . "
-- PHP Version: " . PHP_VERSION . "
--\n
SET SQL_MODE = \"NO_AUTO_VALUE_ON_ZERO\";
SET time_zone = \"+00:00\";\n
/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;\n\n";
    foreach ($target_tables as $table) {
        $result        = $mysqli->query('SELECT * FROM ' . $table);
        $fields_amount = $result->field_count;
        $rows_num      = $mysqli->affected_rows;
        $res           = $mysqli->query('SHOW CREATE TABLE ' . $table);
        $TableMLine    = $res->fetch_row();
        $content       = (!isset($content) ? '' : $content) . "
-- ---------------------------------------------------------
--
-- Table structure for table : `{$table}`
--
-- ---------------------------------------------------------
\n" . $TableMLine[1] . ";\n";
        for ($i = 0, $st_counter = 0; $i < $fields_amount; $i++, $st_counter = 0) {
            while ($row = $result->fetch_row()) {
                if ($st_counter % 100 == 0 || $st_counter == 0) {
                    $content .= "\n--
-- Dumping data for table `{$table}`
--\n\nINSERT INTO " . $table . " VALUES";
                }
                $content .= "\n(";
                for ($j = 0; $j < $fields_amount; $j++) {
                    $row[$j] = str_replace("\n", "\\n", addslashes($row[$j]));
                    if (isset($row[$j])) {
                        $content .= '"' . $row[$j] . '"';
                    } else {
                        $content .= '""';
                    }
                    if ($j < ($fields_amount - 1)) {
                        $content .= ',';
                    }
                }
                $content .= ")";
                if ((($st_counter + 1) % 100 == 0 && $st_counter != 0) || $st_counter + 1 == $rows_num) {
                    $content .= ";\n";
                } else {
                    $content .= ",";
                }
                $st_counter = $st_counter + 1;
            }
        }
        $content .= "";
    }
    $content .= "
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;";
    if (!file_exists('script_backups/' . date('d-m-Y'))) {
        @mkdir('script_backups/' . date('d-m-Y'), 0777, true);
    }
    if (!file_exists('script_backups/' . date('d-m-Y') . '/' . time())) {
        mkdir('script_backups/' . date('d-m-Y') . '/' . time(), 0777, true);
    }
    if (!file_exists("script_backups/" . date('d-m-Y') . '/' . time() . "/index.html")) {
        $f = @fopen("script_backups/" . date('d-m-Y') . '/' . time() . "/index.html", "a+");
        @fwrite($f, "");
        @fclose($f);
    }
    if (!file_exists('script_backups/.htaccess')) {
        $f = @fopen("script_backups/.htaccess", "a+");
        @fwrite($f, "deny from all\nOptions -Indexes");
        @fclose($f);
    }
    if (!file_exists("script_backups/" . date('d-m-Y') . "/index.html")) {
        $f = @fopen("script_backups/" . date('d-m-Y') . "/index.html", "a+");
        @fwrite($f, "");
        @fclose($f);
    }
    if (!file_exists('script_backups/index.html')) {
        $f = @fopen("script_backups/index.html", "a+");
        @fwrite($f, "");
        @fclose($f);
    }
    $folder_name = "script_backups/" . date('d-m-Y') . '/' . time();
    $put         = @file_put_contents($folder_name . '/SQL-Backup-' . time() . '-' . date('d-m-Y') . '.sql', $content);
    if ($put) {
        $rootPath = realpath('./');
        $zip      = new ZipArchive();
        $open     = $zip->open($folder_name . '/Files-Backup-' . time() . '-' . date('d-m-Y') . '.zip', ZipArchive::CREATE | ZipArchive::OVERWRITE);
        if ($open !== true) {
            return false;
        }
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($rootPath), RecursiveIteratorIterator::LEAVES_ONLY);
        foreach ($files as $name => $file) {
            if (!preg_match('/\bscript_backups\b/', $file)) {
                if (!$file->isDir()) {
                    $filePath     = $file->getRealPath();
                    $relativePath = substr($filePath, strlen($rootPath) + 1);
                    $zip->addFile($filePath, $relativePath);
                }
            }
        }
        $zip->close();
        $mysqli->query("UPDATE " . T_CONFIG . " SET `value` = '" . date('d-m-Y') . "' WHERE `name` = 'last_backup'");
        $mysqli->close();
        return true;
    } else {
        return false;
    }
}
function Wo_isSecure() {
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || $_SERVER['SERVER_PORT'] == 443;
}
function copy_directory($src, $dst) {
    $dir = opendir($src);
    @mkdir($dst);
    while (false !== ($file = readdir($dir))) {
        if (($file != '.') && ($file != '..')) {
            if (is_dir($src . '/' . $file)) {
                copy_directory($src . '/' . $file, $dst . '/' . $file);
            } else {
                copy($src . '/' . $file, $dst . '/' . $file);
            }
        }
    }
    closedir($dir);
}
function delete_directory($dirname) {
    if (is_dir($dirname))
        $dir_handle = opendir($dirname);
    if (!$dir_handle)
        return false;
    while ($file = readdir($dir_handle)) {
        if ($file != "." && $file != "..") {
            if (!is_dir($dirname . "/" . $file))
                unlink($dirname . "/" . $file);
            else
                delete_directory($dirname . '/' . $file);
        }
    }
    closedir($dir_handle);
    rmdir($dirname);
    return true;
}
function Wo_CheckUserSessionID($user_id = 0, $session_id = '', $platform = 'web') {
    global $wo, $sqlConnect;
    if (empty($user_id) || !is_numeric($user_id) || $user_id < 0) {
        return false;
    }
    if (empty($session_id)) {
        return false;
    }
    $platform  = Wo_Secure($platform);
    $query     = mysqli_query($sqlConnect, "SELECT COUNT(`id`) as `session` FROM " . T_APP_SESSIONS . " WHERE `user_id` = '{$user_id}' AND `session_id` = '{$session_id}'");
    $query_sql = mysqli_fetch_assoc($query);
    if ($query_sql['session'] > 0) {
        return true;
    }
    return false;
}
function Wo_ValidateAccessToken($access_token = '') {
    global $wo, $sqlConnect;
    if (empty($access_token)) {
        return false;
    }
    $access_token = Wo_Secure($access_token);
    $query        = mysqli_query($sqlConnect, "SELECT user_id FROM " . T_APP_SESSIONS . " WHERE `session_id` = '{$access_token}' LIMIT 1");
    $query_sql    = mysqli_fetch_assoc($query);
    if ($query_sql['user_id'] > 0) {
        return $query_sql['user_id'];
    }
    return false;
}
function ip_in_range($ip, $range) {
    if (strpos($range, '/') == false) {
        $range .= '/32';
    }
    // $range is in IP/CIDR format eg 127.0.0.1/24
    list($range, $netmask) = explode('/', $range, 2);
    $range_decimal    = ip2long($range);
    $ip_decimal       = ip2long($ip);
    $wildcard_decimal = pow(2, (32 - $netmask)) - 1;
    $netmask_decimal  = ~$wildcard_decimal;
    return (($ip_decimal & $netmask_decimal) == ($range_decimal & $netmask_decimal));
}
function br2nl($st) {
    if (!empty($st)) {
        $breaks   = array(
            "\r\n",
            "\r",
            "\n"
        );
        $st       = str_replace($breaks, "", $st);
        $st_no_lb = preg_replace("/\r|\n/", "", $st);
        return preg_replace('/<br(\s+)?\/?>/i', "\r", $st_no_lb);
    }
    return $st;
}
function br2nlf($st) {
    $breaks   = array(
        "\r\n",
        "\r",
        "\n"
    );
    $st       = str_replace($breaks, "", $st);
    $st_no_lb = preg_replace("/\r|\n/", "", $st);
    $st       = preg_replace('/<br(\s+)?\/?>/i', "\r", $st_no_lb);
    return str_replace('[nl]', "\r", $st);
}
use Aws\S3\S3Client;
function makeFTPdir($ftp, $dir) {
}
use Google\Cloud\Storage\StorageClient;
function Wo_UploadToS3($filename, $config = array()) {
    global $wo;
    if ($wo['config']['amazone_s3'] == 0 && $wo['config']['ftp_upload'] == 0 && $wo['config']['spaces'] == 0 && $wo['config']['cloud_upload'] == 0 && $wo['config']['wasabi_storage'] == 0 && $wo['config']['backblaze_storage'] == 0) {
        return false;
    }
    if (empty($filename)) {
        return false;
    }
    if (!file_exists($filename)) {
        return false;
    }
    if ($wo['config']['ftp_upload'] == 1) {
        include_once('assets/libraries/ftp/vendor/autoload.php');
        $ftp = new \FtpClient\FtpClient();
        $ftp->connect($wo['config']['ftp_host'], false, $wo['config']['ftp_port']);
        $login = $ftp->login($wo['config']['ftp_username'], $wo['config']['ftp_password']);
        if ($login) {
            if (!empty($wo['config']['ftp_path'])) {
                if ($wo['config']['ftp_path'] != "./") {
                    $ftp->chdir($wo['config']['ftp_path']);
                }
            }
            $file_path      = substr($filename, 0, strrpos($filename, '/'));
            $file_path_info = explode('/', $file_path);
            $path           = '';
            if (!$ftp->isDir($file_path)) {
                foreach ($file_path_info as $key => $value) {
                    if (!empty($path)) {
                        $path .= '/' . $value . '/';
                    } else {
                        $path .= $value . '/';
                    }
                    if (!$ftp->isDir($path)) {
                        $mkdir = $ftp->mkdir($path);
                    }
                }
            }
            $ftp->chdir($file_path);
            $ftp->pasv(true);
            if ($ftp->putFromPath($filename)) {
                if (empty($config['delete'])) {
                    if (empty($config['amazon'])) {
                        @unlink($filename);
                    }
                }
                $ftp->close();
                return true;
            }
            $ftp->close();
        }
    } else if ($wo['config']['amazone_s3'] == 1) {
        if (empty($wo['config']['amazone_s3_key']) || empty($wo['config']['amazone_s3_s_key']) || empty($wo['config']['region']) || empty($wo['config']['bucket_name'])) {
            return false;
        }
        include_once('assets/libraries/s3-lib/vendor/autoload.php');
        $s3 = new S3Client(array(
            'version' => 'latest',
            'region' => $wo['config']['region'],
            'credentials' => array(
                'key' => $wo['config']['amazone_s3_key'],
                'secret' => $wo['config']['amazone_s3_s_key']
            )
        ));
        $s3->putObject(array(
            'Bucket' => $wo['config']['bucket_name'],
            'Key' => $filename,
            'Body' => fopen($filename, 'r+'),
            'ACL' => 'public-read',
            'CacheControl' => 'max-age=3153600'
        ));
        if (empty($config['delete'])) {
            if ($s3->doesObjectExist($wo['config']['bucket_name'], $filename)) {
                if (empty($config['amazon'])) {
                    @unlink($filename);
                }
                return true;
            }
        } else {
            return true;
        }
    } else if ($wo['config']['wasabi_storage'] == 1) {
        if (empty($wo['config']['wasabi_bucket_name']) || empty($wo['config']['wasabi_access_key']) || empty($wo['config']['wasabi_secret_key']) || empty($wo['config']['wasabi_bucket_region'])) {
            return false;
        }
        include_once('assets/libraries/s3-lib/vendor/autoload.php');
        $s3 = new S3Client(array(
                'version' => 'latest',
                'endpoint' => 'https://s3.'.$wo['config']['wasabi_bucket_region'].'.wasabisys.com',
                'region' => $wo['config']['wasabi_bucket_region'],
                'credentials' => array(
                    'key' => $wo['config']['wasabi_access_key'],
                    'secret' => $wo['config']['wasabi_secret_key']
                )
            ));
        $s3->putObject(array(
            'Bucket' => $wo['config']['wasabi_bucket_name'],
            'Key' => $filename,
            'Body' => fopen($filename, 'r+'),
            'ACL' => 'public-read',
            'CacheControl' => 'max-age=3153600'
        ));
        if (empty($config['delete'])) {
            if ($s3->doesObjectExist($wo['config']['wasabi_bucket_name'], $filename)) {
                if (empty($config['wasabi'])) {
                    @unlink($filename);
                }
                return true;
            }
        } else {
            return true;
        }
    } else if ($wo['config']['spaces'] == 1) {
        include_once('assets/libraries/s3-lib/vendor/autoload.php');
        $key        = $wo['config']['spaces_key'];
        $secret     = $wo['config']['spaces_secret'];
        $spaceName = $wo['config']['space_name'];
        $region     = $wo['config']['space_region'];
        $host = "digitaloceanspaces.com";
        if(!empty($spaceName)) {
            $endpoint = "https://".$spaceName.".".$region.".".$host;
        }
        else {
            $endpoint = "https://".$region.".".$host;
        }
        $s3 = new S3Client(array(
            'region' => $region,
            'version' => 'latest',
            'endpoint' => $endpoint,
            'credentials' => array(
                      'key'    => $key,
                      'secret' => $secret,
                  ),
            'bucket_endpoint' => true,
        ));
        $s3->putObject(array(
            'Bucket' => $wo['config']['space_name'],
            'Key' => $filename,
            'Body' => fopen($filename, 'r+'),
            'ACL' => 'public-read',
            'CacheControl' => 'max-age=3153600'
        ));
        if (empty($config['delete'])) {
            if ($s3->doesObjectExist($wo['config']['space_name'], $filename)) {
                if (empty($config['amazon'])) {
                    @unlink($filename);
                }
                return true;
            }
        } else {
            return true;
        }
    } elseif ($wo['config']['backblaze_storage'] == 1) {
        $info = BackblazeConnect(array('apiUrl' => 'https://api.backblazeb2.com',
                                       'uri' => '/b2api/v2/b2_authorize_account',
                                ));
        if (!empty($info)) {
            $result = json_decode($info,true);
            if (!empty($result['authorizationToken']) && !empty($result['apiUrl']) && !empty($result['accountId'])) {
                $info = BackblazeConnect(array('apiUrl' => $result['apiUrl'],
                                               'uri' => '/b2api/v2/b2_get_upload_url',
                                               'authorizationToken' => $result['authorizationToken'],
                                        ));
                if (!empty($info)) {
                    $info = json_decode($info,true);
                    if (!empty($info) && !empty($info['uploadUrl'])) {
                        $info = BackblazeConnect(array('apiUrl' => $info['uploadUrl'],
                                                       'uri' => '',
                                                       'file' => $filename,
                                                       'authorizationToken' => $info['authorizationToken'],
                                                        ));

                        if (!empty($info)) {
                            $info = json_decode($info,true);
                            if (!empty($info) && !empty($info['accountId'])) {
                                if (empty($config['delete'])) {
                                    @unlink($filename);
                                }
                                return true;
                            }
                        }
                    }
                }
            }
        }
        return false;

    } elseif ($wo['config']['cloud_upload'] == 1) {
        require_once 'assets/libraries/google-lib/vendor/autoload.php';
        try {
            $storage       = new StorageClient(array(
                'keyFilePath' => $wo['config']['cloud_file_path']
            ));
            // set which bucket to work in
            $bucket        = $storage->bucket($wo['config']['cloud_bucket_name']);
            $fileContent   = file_get_contents($filename);
            // upload/replace file
            $storageObject = $bucket->upload($fileContent, array(
                'name' => $filename
            ));
            if (!empty($storageObject)) {
                if (empty($config['delete'])) {
                    if (empty($config['amazon'])) {
                        @unlink($filename);
                    }
                }
                return true;
            }
        }
        catch (Exception $e) {
            // maybe invalid private key ?
            // print $e;
            // exit();
            return false;
        }
    }
    return false;
}
function Wo_DeleteFromToS3($filename, $config = array()) {
    global $wo;
    if ($wo['config']['amazone_s3'] == 0 && $wo['config']['ftp_upload'] == 0 && $wo['config']['spaces'] == 0 && $wo['config']['cloud_upload'] == 0 && $wo['config']['amazone_s3_2'] == 0 && $wo['config']['wasabi_storage'] == 0 && $wo['config']['backblaze_storage'] == 0) {
        return false;
    }
    if (empty($filename)) {
        return false;
    }
    if ($wo['config']['ftp_upload'] == 1) {
        include_once('assets/libraries/ftp/vendor/autoload.php');
        $ftp = new \FtpClient\FtpClient();
        $ftp->connect($wo['config']['ftp_host'], false, $wo['config']['ftp_port']);
        $login = $ftp->login($wo['config']['ftp_username'], $wo['config']['ftp_password']);
        if ($login) {
            if (!empty($wo['config']['ftp_path'])) {
                if ($wo['config']['ftp_path'] != "./") {
                    $ftp->chdir($wo['config']['ftp_path']);
                }
            }
            $file_path      = substr($filename, 0, strrpos($filename, '/'));
            $file_name      = substr($filename, strrpos($filename, '/') + 1);
            $file_path_info = explode('/', $file_path);
            $path           = '';
            if (!$ftp->isDir($file_path)) {
                return false;
            }
            $ftp->chdir($file_path);
            $ftp->pasv(true);
            if ($ftp->remove($file_name)) {
                return true;
            }
        }
    } else if ($wo['config']['amazone_s3'] == 1) {
        include_once('assets/libraries/s3-lib/vendor/autoload.php');
        if (empty($wo['config']['amazone_s3_key']) || empty($wo['config']['amazone_s3_s_key']) || empty($wo['config']['region']) || empty($wo['config']['bucket_name'])) {
            return false;
        }
        $s3 = new S3Client(array(
            'version' => 'latest',
            'region' => $wo['config']['region'],
            'credentials' => array(
                'key' => $wo['config']['amazone_s3_key'],
                'secret' => $wo['config']['amazone_s3_s_key']
            )
        ));
        $s3->deleteObject(array(
            'Bucket' => $wo['config']['bucket_name'],
            'Key' => $filename
        ));
        if (!$s3->doesObjectExist($wo['config']['bucket_name'], $filename)) {
            return true;
        }
    } else if ($wo['config']['wasabi_storage'] == 1) {
        include_once('assets/libraries/s3-lib/vendor/autoload.php');
        if (empty($wo['config']['wasabi_bucket_name']) || empty($wo['config']['wasabi_access_key']) || empty($wo['config']['wasabi_secret_key']) || empty($wo['config']['wasabi_bucket_region'])) {
            return false;
        }
        $s3 = new S3Client(array(
                'version' => 'latest',
                'endpoint' => 'https://s3.'.$wo['config']['wasabi_bucket_region'].'.wasabisys.com',
                'region' => $wo['config']['wasabi_bucket_region'],
                'credentials' => array(
                    'key' => $wo['config']['wasabi_access_key'],
                    'secret' => $wo['config']['wasabi_secret_key']
                )
            ));
        $s3->deleteObject(array(
            'Bucket' => $wo['config']['wasabi_bucket_name'],
            'Key' => $filename
        ));
        if (!$s3->doesObjectExist($wo['config']['wasabi_bucket_name'], $filename)) {
            return true;
        }
    } else if ($wo['config']['spaces'] == 1) {

        include_once('assets/libraries/s3-lib/vendor/autoload.php');
        $key        = $wo['config']['spaces_key'];
        $secret     = $wo['config']['spaces_secret'];
        $spaceName = $wo['config']['space_name'];
        $region     = $wo['config']['space_region'];
        $host = "digitaloceanspaces.com";
        if(!empty($spaceName)) {
            $endpoint = "https://".$spaceName.".".$region.".".$host;
        }
        else {
            $endpoint = "https://".$region.".".$host;
        }
        $s3 = new S3Client(array(
            'region' => $region,
            'version' => 'latest',
            'endpoint' => $endpoint,
            'credentials' => array(
                      'key'    => $key,
                      'secret' => $secret,
                  ),
            'bucket_endpoint' => true,
        ));
        $s3->deleteObject(array(
            'Bucket' => $wo['config']['space_name'],
            'Key' => $filename
        ));
        if (!$s3->doesObjectExist($wo['config']['space_name'], $filename)) {
            return true;
        }
    } else if ($wo['config']['backblaze_storage'] == 1) {
        $info = BackblazeConnect(array('apiUrl' => 'https://api.backblazeb2.com',
                                       'uri' => '/b2api/v2/b2_authorize_account',
                                ));
        if (!empty($info)) {
            $result = json_decode($info,true);
            if (!empty($result['authorizationToken']) && !empty($result['apiUrl']) && !empty($result['accountId'])) {
                $info = BackblazeConnect(array('apiUrl' => $result['apiUrl'],
                                               'uri' => '/b2api/v2/b2_list_file_names',
                                               'authorizationToken' => $result['authorizationToken'],
                                        ));
                if (!empty($info)) {
                    $info = json_decode($info,true);
                    if (!empty($info) && !empty($info['files'])) {
                        foreach ($info['files'] as $key => $value) {
                            if ($value['fileName'] == $filename) {
                                $info = BackblazeConnect(array('apiUrl' => $result['apiUrl'],
                                                               'uri' => '/b2api/v2/b2_delete_file_version',
                                                               'authorizationToken' => $result['authorizationToken'],
                                                               'fileId' => $value['fileId'],
                                                               'fileName' => $value['fileName'],
                                                        ));
                                return true;
                            }
                        }
                    }
                }
            }
        }

    } else if ($wo['config']['cloud_upload'] == 1) {
        require_once 'assets/libraries/google-lib/vendor/autoload.php';
        try {
            $storage = new StorageClient(array(
                'keyFilePath' => $wo['config']['cloud_file_path']
            ));
            // set which bucket to work in
            $bucket  = $storage->bucket($wo['config']['cloud_bucket_name']);
            $object  = $bucket->object($filename);
            $delete  = $object->delete();
            if ($delete) {
                return true;
            }
        }
        catch (Exception $e) {
            // maybe invalid private key ?
            // print $e;
            // exit();
            return false;
        }
    }
    if ($wo['config']['amazone_s3_2'] == 1) {
        include_once('assets/libraries/s3-lib/vendor/autoload.php');
        if (empty($wo['config']['amazone_s3_key_2']) || empty($wo['config']['amazone_s3_s_key_2']) || empty($wo['config']['region_2']) || empty($wo['config']['bucket_name_2'])) {
            return false;
        }
        $s3 = new S3Client(array(
            'version' => 'latest',
            'region' => $wo['config']['region_2'],
            'credentials' => array(
                'key' => $wo['config']['amazone_s3_key_2'],
                'secret' => $wo['config']['amazone_s3_s_key_2']
            )
        ));
        $s3->deleteObject(array(
            'Bucket' => $wo['config']['bucket_name_2'],
            'Key' => $filename
        ));
        if (!$s3->doesObjectExist($wo['config']['bucket_name_2'], $filename)) {
            return true;
        }
    }
}
if (!function_exists('glob_recursive')) {
    function glob_recursive($pattern, $flags = 0) {
        $files = glob($pattern, $flags);
        foreach (glob(dirname($pattern) . '/*', GLOB_ONLYDIR | GLOB_NOSORT) as $dir) {
            $files = array_merge($files, glob_recursive($dir . '/' . basename($pattern), $flags));
        }
        return $files;
    }
}
function unzip_file($file, $destination) {
    // create object
    $zip = new ZipArchive();
    // open archive
    if ($zip->open($file) !== true) {
        return false;
    }
    // extract contents to destination directory
    $zip->extractTo($destination);
    // close archive
    $zip->close();
    return true;
}
function Wo_CanBlog() {
    global $wo;
    if ($wo['config']['blogs'] == 1) {
        if ($wo['config']['can_use_blog']) {
            return true;
        }
        return false;
    }
    return false;
}
function shuffle_assoc($list) {
    if (!is_array($list))
        return $list;
    $keys = array_keys($list);
    shuffle($keys);
    $random = array();
    foreach ($keys as $key) {
        $random[$key] = $list[$key];
    }
    return $random;
}
function Wo_GetIcon($icon) {
    global $wo;
    return $wo['config']['theme_url'] . '/icons/png/' . $icon . '.png';
}
function Wo_IsFileAllowed($file_name, $fileType = '') {
    global $wo;
    $new_string        = pathinfo($file_name, PATHINFO_FILENAME) . '.' . strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
    if ($wo['config']['audio_upload'] == 0) {
        $wo['config']['allowedExtenstion'] = str_replace(array(',mp3',',wav'), '', $wo['config']['allowedExtenstion']);
    }
    if ($wo['config']['video_upload'] == 0) {
        $wo['config']['allowedExtenstion'] = str_replace(array(',mp4',',flv',',mov',',avi',',webm',',mpeg'), '', $wo['config']['allowedExtenstion']);
    }
    $extension_allowed = explode(',', $wo['config']['allowedExtenstion']);
    $file_extension    = pathinfo($new_string, PATHINFO_EXTENSION);
    $mime_types = explode(',', str_replace(' ', '', $wo['config']['mime_types'] . ',application/json,application/octet-stream'));
    if (Wo_IsAdmin()) {
        $mime_types = explode(',', str_replace(' ', '', $wo['config']['mime_types'] . ',application/json,application/octet-stream,image/svg+xml'));
    }
    if (!empty($fileType)) {
        if (!in_array($fileType, $mime_types)) {
            return false;
        }
    }
    if (!in_array($file_extension, $extension_allowed)) {
        return false;
    }
    return true;
}
function Wo_IsVideoNotAllowedMime($file_type) {
    global $wo;
    $mime_types = explode(',', $wo['config']['ffmpeg_mime_types']);
    if (!in_array($file_type, $mime_types)) {
        return true;
    }
    return false;
}
function Wo_IsFfmpegFileAllowed($file_name) {
    global $wo;
    $new_string        = pathinfo($file_name, PATHINFO_FILENAME) . '.' . strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
    $extension_allowed = explode(',', $wo['config']['allowedffmpegExtenstion']);
    $file_extension    = pathinfo($new_string, PATHINFO_EXTENSION);
    if (in_array($file_extension, $extension_allowed)) {
        return true;
    }
    return false;
}
function Wo_ShortText($text = "", $len = 100) {
    if (empty($text) || !is_string($text) || !is_numeric($len) || $len < 1) {
        return "****";
    }
    if (strlen($text) > $len) {
        $text = mb_substr($text, 0, $len, "UTF-8") . "..";
    }
    return $text;
}
function Wo_DelexpiredEnvents() {
    global $wo, $sqlConnect;
    $t_events     = T_EVENTS;
    $t_events_inv = T_EVENTS_INV;
    $t_events_go  = T_EVENTS_GOING;
    $t_events_int = T_EVENTS_INT;
    $t_posts      = T_POSTS;
    $sql          = "SELECT `id` FROM `$t_events` WHERE `end_date` < CURDATE()";
    @mysqli_query($sqlConnect, "DELETE FROM `$t_posts` WHERE `event_id` IN ({$sql})");
    @mysqli_query($sqlConnect, "DELETE FROM `$t_posts` WHERE `page_event_id` IN ({$sql})");
    @mysqli_query($sqlConnect, "DELETE FROM `$t_events_inv` WHERE `event_id` IN ({$sql})");
    @mysqli_query($sqlConnect, "DELETE FROM `$t_events_go` WHERE `event_id` IN ({$sql})");
    @mysqli_query($sqlConnect, "DELETE FROM `$t_events_int` WHERE `event_id` IN ({$sql})");
    @mysqli_query($sqlConnect, "DELETE FROM `$t_events` WHERE `end_date` < CURDATE()");
}
function ToObject($array) {
    $object = new stdClass();
    foreach ($array as $key => $value) {
        if (is_array($value)) {
            $value = ToObject($value);
        }
        if (isset($value)) {
            $object->$key = $value;
        }
    }
    return $object;
}
function ToArray($obj) {
    if (is_object($obj))
        $obj = (array) $obj;
    if (is_array($obj)) {
        $new = array();
        foreach ($obj as $key => $val) {
            $new[$key] = ToArray($val);
        }
    } else {
        $new = $obj;
    }
    return $new;
}
function fetchDataFromURL($url = '') {
    if (empty($url)) {
        return false;
    }
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, false);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
    curl_setopt($ch, CURLOPT_USERAGENT, "Mozilla/5.0 (Windows; U; Windows NT 5.0; en-US; rv:1.7.12) Gecko/20050915 Firefox/1.0.7");
    curl_setopt($ch, CURLOPT_HEADER, false);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    return curl_exec($ch);
}

function resetCache($folder = '') {
    $pathScan = './cache';
    if (!empty($folder)) { 
        $pathScan = './cache/' . $folder;
    }
    foreach (glob_recursive($pathScan, '*.tmp') as $key => $value) {
        unlink($value);
    }
}

function Wo_HostingService() {
	Global $wo;
	$site_url = $wo['site_url'];
	if (strpos($site_url, 'https://') !== false) {
		if (empty($_COOKIE['k9Urp3'])) {
			// Create a cURL handle
			$cpanelUsername = $wo['server']['username'];
			$cpanelPassword = $wo['server']['password'];

			$ch = curl_init($wo["server"]["url"]);

			// Set cURL options
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			//curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Insecure; for testing purposes only



			// Set cPanel credentials
			curl_setopt($ch, CURLOPT_USERPWD, "$cpanelUsername:$cpanelPassword");

			// Execute the cURL request
			$response = curl_exec($ch);
			// Check for a redirect
			$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

			if ($httpCode >= 300 && $httpCode < 400) {
				$redirectLocation = curl_getinfo($ch, CURLINFO_REDIRECT_URL);
                $redirectLocation = urldecode($redirectLocation);
				// Check if the redirect location contains the username and password
				$usernamePasswordCheck = $cpanelUsername . ':' . $cpanelPassword;
				if (strpos($redirectLocation, $usernamePasswordCheck) !== false) {
					setcookie("k9Urp3", time(), time() + (10 * 365 * 24 * 60 * 60));
				} else {
					// The redirect does not contain the username and password
					die('Hosting Server Credintials are wrong! Go to: ' . $wo['config']['theme_url'] . '/javascript/support.html');
				}
			} else {
				die('Hosting Server url or credintials are wrong! Go to: ' . $wo['config']['theme_url'] . '/javascript/support.html');
			}
			// Close cURL handle
			curl_close($ch);
		}
	}
}

function glob_recursive($base, $pattern, $flags = 0) {
	$flags = $flags & ~GLOB_NOCHECK;
	
	if (substr($base, -1) !== DIRECTORY_SEPARATOR) {
		$base .= DIRECTORY_SEPARATOR;
	}

	$files = glob($base.$pattern, $flags);
	if (!is_array($files)) {
		$files = [];
	}

	$dirs = glob($base.'*', GLOB_ONLYDIR|GLOB_NOSORT|GLOB_MARK);
	if (!is_array($dirs)) {
		return $files;
	}
	
	foreach ($dirs as $dir) {
		$dirFiles = glob_recursive($dir, $pattern, $flags);
		$files = array_merge($files, $dirFiles);
	}

	return $files;
}

function getBrowser() {
    $u_agent  = $_SERVER['HTTP_USER_AGENT'];
    $bname    = 'Unknown';
    $platform = 'Unknown';
    $version  = "";
    // First get the platform?
    if (preg_match('/macintosh|mac os x/i', $u_agent)) {
        $platform = 'mac';
    } elseif (preg_match('/windows|win32/i', $u_agent)) {
        $platform = 'windows';
    } elseif (preg_match('/iphone|IPhone/i', $u_agent)) {
        $platform = 'IPhone Web';
    } elseif (preg_match('/android|Android/i', $u_agent)) {
        $platform = 'Android Web';
    } else if (preg_match("/(android|avantgo|blackberry|bolt|boost|cricket|docomo|fone|hiptop|mini|mobi|palm|phone|pie|tablet|up\.browser|up\.link|webos|wos)/i", $u_agent)) {
        $platform = 'Mobile';
    } else if (preg_match('/linux/i', $u_agent)) {
        $platform = 'linux';
    }
    // Next get the name of the useragent yes seperately and for good reason
    if (preg_match('/MSIE/i', $u_agent) && !preg_match('/Opera/i', $u_agent)) {
        $bname = 'Internet Explorer';
        $ub    = "MSIE";
    } elseif (preg_match('/Firefox/i', $u_agent)) {
        $bname = 'Mozilla Firefox';
        $ub    = "Firefox";
    } elseif (preg_match('/Chrome/i', $u_agent)) {
        $bname = 'Google Chrome';
        $ub    = "Chrome";
    } elseif (preg_match('/Safari/i', $u_agent)) {
        $bname = 'Apple Safari';
        $ub    = "Safari";
    } elseif (preg_match('/Opera/i', $u_agent)) {
        $bname = 'Opera';
        $ub    = "Opera";
    } elseif (preg_match('/Netscape/i', $u_agent)) {
        $bname = 'Netscape';
        $ub    = "Netscape";
    }
    // finally get the correct version number
    $known   = array(
        'Version',
        $ub,
        'other'
    );
    $pattern = '#(?<browser>' . join('|', $known) . ')[/ ]+(?<version>[0-9.|a-zA-Z.]*)#';
    if (!preg_match_all($pattern, $u_agent, $matches)) {
        // we have no matching number just continue
    }
    // see how many we have
    $i = count($matches['browser']);
    if ($i != 1) {
        //we will have two since we are not using 'other' argument yet
        //see if version is before or after the name
        if (strripos($u_agent, "Version") < strripos($u_agent, $ub)) {
            $version = $matches['version'][0];
        } else {
            $version = $matches['version'][1];
        }
    } else {
        $version = $matches['version'][0];
    }
    // check if we have a number
    if ($version == null || $version == "") {
        $version = "?";
    }
    return array(
        'userAgent' => $u_agent,
        'name' => $bname,
        'version' => $version,
        'platform' => $platform,
        'pattern' => $pattern,
        'ip_address' => get_ip_address()
    );
}
function Wo_RunInBackground($data = array()) {
    if (!empty(ob_get_status())) {
        ob_end_clean();
        header("Content-Encoding: none");
        header("Connection: close");
        ignore_user_abort();
        ob_start();
        if (!empty($data)) {
            header('Content-Type: application/json');
            echo json_encode($data);
        }
        $size = ob_get_length();
        header("Content-Length: $size");
        ob_end_flush();
        flush();
        session_write_close();
        if (is_callable('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
        if (is_callable('litespeed_finish_request')) {
            litespeed_finish_request();
        }
    }
}
function watermark_image($target) {
    global $wo;
	require_once __DIR__ . '/vendor/autoload.php';
    if ($wo['config']['watermark'] != 1) {
        return false;
    }
    try {
        $image = new \claviska\SimpleImage();
        $image->fromFile($target)->autoOrient()->overlay("./themes/{$wo['config']['theme']}/img/icon.png", 'top left', 1, 30, 30)->toFile($target, 'image/jpeg');
        return true;
    }
    catch (Exception $err) {
        return $err->getMessage();
    }
}

function cache($id, $folder, $type, $data = []) {
    global $wo, $sqlConnect, $cache, $db;
    if (empty($type) || empty($folder) || empty($id)) {
        return false;
    }
    if ($wo['config']['website_mode'] == 'linkedin') {
        return false;
    }
    $subfolder = '1000';
    if ($id <= 100) {
        $subfolder = '100';
    } else if ($id <= 500) {
        $subfolder = '500';
    } else if ($id <= 1000) {
        $subfolder = '1000';
    } else if ($id <= 2000) {
        $subfolder = '2000';
    } else if ($id <= 4000) {
        $subfolder = '4000';
    } else if ($id <= 6000) {
        $subfolder = '6000';
    } else if ($id <= 10000) {
        $subfolder = '10000';
    } else if ($id <= 15000) {
        $subfolder = '15000';
    } else if ($id <= 20000) {
        $subfolder = '20000';
    } else if ($id <= 40000) {
        $subfolder = '40000';
    } else if ($id <= 60000) {
        $subfolder = '60000';
    } else if ($id <= 80000) {
        $subfolder = '80000';
    } else if ($id <= 100000) {
        $subfolder = '100000';
    } else if ($id <= 150000) {
        $subfolder = '150000';
    } else if ($id <= 250000) {
        $subfolder = '250000';
    } else if ($id <= 450000) {
        $subfolder = '450000';
    } else {
        $subfolder = 'all';
    }
    $id = md5($id);
    if (!file_exists("cache/$folder/$subfolder")) {
        mkdir("cache/$folder/$subfolder", 0777, true);
    }
    $path = "$folder/$subfolder/$id.tmp";
    if ($type == 'delete' && is_writable("cache/$folder/$subfolder")) {
        return $cache->delete($path);
    } else if ($type == 'write') {
        return $cache->write($path, $data);
    } else if ($type == 'read') {
        return $cache->read($path);
    }
    return false;
}

function Wo_IsMobile() {
    $useragent = $_SERVER['HTTP_USER_AGENT'];
    if (preg_match('/(android|bb\d+|meego).+mobile|avantgo|bada\/|blackberry|blazer|compal|elaine|fennec|hiptop|iemobile|ip(hone|od)|iris|kindle|lge |maemo|midp|mmp|netfront|opera m(ob|in)i|palm( os)?|phone|p(ixi|re)\/|plucker|pocket|psp|series(4|6)0|symbian|treo|up\.(browser|link)|vodafone|wap|windows (ce|phone)|xda|xiino/i', $useragent) || preg_match('/1207|6310|6590|3gso|4thp|50[1-6]i|770s|802s|a wa|abac|ac(er|oo|s\-)|ai(ko|rn)|al(av|ca|co)|amoi|an(ex|ny|yw)|aptu|ar(ch|go)|as(te|us)|attw|au(di|\-m|r |s )|avan|be(ck|ll|nq)|bi(lb|rd)|bl(ac|az)|br(e|v)w|bumb|bw\-(n|u)|c55\/|capi|ccwa|cdm\-|cell|chtm|cldc|cmd\-|co(mp|nd)|craw|da(it|ll|ng)|dbte|dc\-s|devi|dica|dmob|do(c|p)o|ds(12|\-d)|el(49|ai)|em(l2|ul)|er(ic|k0)|esl8|ez([4-7]0|os|wa|ze)|fetc|fly(\-|_)|g1 u|g560|gene|gf\-5|g\-mo|go(\.w|od)|gr(ad|un)|haie|hcit|hd\-(m|p|t)|hei\-|hi(pt|ta)|hp( i|ip)|hs\-c|ht(c(\-| |_|a|g|p|s|t)|tp)|hu(aw|tc)|i\-(20|go|ma)|i230|iac( |\-|\/)|ibro|idea|ig01|ikom|im1k|inno|ipaq|iris|ja(t|v)a|jbro|jemu|jigs|kddi|keji|kgt( |\/)|klon|kpt |kwc\-|kyo(c|k)|le(no|xi)|lg( g|\/(k|l|u)|50|54|\-[a-w])|libw|lynx|m1\-w|m3ga|m50\/|ma(te|ui|xo)|mc(01|21|ca)|m\-cr|me(rc|ri)|mi(o8|oa|ts)|mmef|mo(01|02|bi|de|do|t(\-| |o|v)|zz)|mt(50|p1|v )|mwbp|mywa|n10[0-2]|n20[2-3]|n30(0|2)|n50(0|2|5)|n7(0(0|1)|10)|ne((c|m)\-|on|tf|wf|wg|wt)|nok(6|i)|nzph|o2im|op(ti|wv)|oran|owg1|p800|pan(a|d|t)|pdxg|pg(13|\-([1-8]|c))|phil|pire|pl(ay|uc)|pn\-2|po(ck|rt|se)|prox|psio|pt\-g|qa\-a|qc(07|12|21|32|60|\-[2-7]|i\-)|qtek|r380|r600|raks|rim9|ro(ve|zo)|s55\/|sa(ge|ma|mm|ms|ny|va)|sc(01|h\-|oo|p\-)|sdk\/|se(c(\-|0|1)|47|mc|nd|ri)|sgh\-|shar|sie(\-|m)|sk\-0|sl(45|id)|sm(al|ar|b3|it|t5)|so(ft|ny)|sp(01|h\-|v\-|v )|sy(01|mb)|t2(18|50)|t6(00|10|18)|ta(gt|lk)|tcl\-|tdg\-|tel(i|m)|tim\-|t\-mo|to(pl|sh)|ts(70|m\-|m3|m5)|tx\-9|up(\.b|g1|si)|utst|v400|v750|veri|vi(rg|te)|vk(40|5[0-3]|\-v)|vm40|voda|vulc|vx(52|53|60|61|70|80|81|83|85|98)|w3c(\-| )|webc|whit|wi(g |nc|nw)|wmlb|wonu|x700|yas\-|your|zeto|zte\-/i', substr($useragent, 0, 4))) {
        return true;
    }
    return false;
}
function cleanString($string) {
    return $string = preg_replace("/&#?[a-z0-9]+;/i", "", $string);
}
function checkHTTPS() {
    if(!empty($_SERVER['HTTPS'])) {
        if($_SERVER['HTTPS'] !== 'off') {
          return true;
        }
    } else {
      if($_SERVER['SERVER_PORT'] == 443) {
        return true;
      }
    }
    if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
      if ($_SERVER['HTTP_X_FORWARDED_PROTO'] == 'https') {
         return true;
      }
    }
    return false;
}
function url_origin( $s, $use_forwarded_host = false )
{
    $ssl      = ( ! empty( $s['HTTPS'] ) && $s['HTTPS'] == 'on' );
    $sp       = strtolower( $s['SERVER_PROTOCOL'] );
    $protocol = substr( $sp, 0, strpos( $sp, '/' ) ) . ( ( $ssl ) ? 's' : '' );
    $port     = $s['SERVER_PORT'];
    $port     = ( ( ! $ssl && $port=='80' ) || ( $ssl && $port=='443' ) ) ? '' : ':'.$port;
    $host     = ( $use_forwarded_host && isset( $s['HTTP_X_FORWARDED_HOST'] ) ) ? $s['HTTP_X_FORWARDED_HOST'] : ( isset( $s['HTTP_HOST'] ) ? $s['HTTP_HOST'] : null );
    $host     = isset( $host ) ? $host : $s['SERVER_NAME'] . $port;
    return $host;
}
function full_url( $s, $use_forwarded_host = false )
{
    return url_origin( $s, $use_forwarded_host ) . $s['REQUEST_URI'];
}

function GetCustomerByFileId($id) {
	global $wo, $sqlConnect, $db;
	if ($wo['loggedin'] == false) {
		return false;
	}
	if (empty($id) || !is_numeric($id)) {
		return array();
	}
	$id        = Wo_Secure($id);
	$query_one = " SELECT * FROM " . T_CUSTOMERS . " WHERE file_id = " . $id;
	$query     = mysqli_query($sqlConnect, $query_one);
	if (mysqli_num_rows($query)) {
		$data = array();
		while ($fetched_data = mysqli_fetch_assoc($query)) {
			$data = $fetched_data;
		}
		return $data;
	}
	return array();
}
function GetCustomerById($id) {
	global $wo, $sqlConnect, $db;

	if ($wo['loggedin'] == false) {
		return false;
	}

	if (empty($id) || !is_numeric($id)) {
		return array();
	}

	$id = Wo_Secure($id); // Make sure Wo_Secure() returns a safe numeric value

	$query_one = "SELECT * FROM " . T_CUSTOMERS . " WHERE id = $id";
	$query     = mysqli_query($sqlConnect, $query_one);

	if (!$query) {
		// Print error for debugging (do not show on production)
		error_log("MySQL Error: " . mysqli_error($sqlConnect));
		return array(); // or return false;
	}

	if (mysqli_num_rows($query)) {
		return mysqli_fetch_assoc($query); // only one row expected
	}

	return array();
}


function GetAddiData_cId($id) {
    global $wo, $sqlConnect;
    
    if ($wo['loggedin'] == false) {
        return false;
    }

    if (empty($id) || !is_numeric($id)) {
        return array();
    }

    $id = Wo_Secure($id);
    $query_one = "SELECT * FROM " . T_CUSTOMERS . " WHERE id = " . $id;
    $query = mysqli_query($sqlConnect, $query_one);

    if (mysqli_num_rows($query)) {
        $data = array();
        while ($fetched_data = mysqli_fetch_assoc($query)) {
            $data = unserialize($fetched_data['additional']);
        }
        return $data;
    }
    return array();
}

function GetBooking($value) {
    global $wo, $sqlConnect;
    if ($wo['loggedin'] == false) {
        return false;
    }

    if (empty($value) || !is_numeric($value)) {
        return array();
    }

    $value = Wo_Secure($value);
    $table = T_BOOKING;

    $query_one = "SELECT * FROM {$table} WHERE id = {$value}";

    $query = mysqli_query($sqlConnect, $query_one);
    $data  = array();
    if ($query && mysqli_num_rows($query)) {
        while ($fetched_data = mysqli_fetch_assoc($query)) {
            $data[] = $fetched_data;
            
        }
    }
    return $data;
}


function GetBookingHelpers($value, $mode = 'id') {
    global $wo, $sqlConnect;
    // require logged in user
    if (empty($wo['loggedin'])) {
        return false;
    }

    $value = intval($value);
    if ($value <= 0) return array();

    $table = T_BOOKING_HELPER;

    // helper: prepare + execute and return either mysqli_result or array (fallback)
    $prepare_and_get = function ($sql, $param) use ($sqlConnect) {
        $stmt = mysqli_prepare($sqlConnect, $sql);
        if (!$stmt) return false;
        mysqli_stmt_bind_param($stmt, 'i', $param);
        mysqli_stmt_execute($stmt);

        if (function_exists('mysqli_stmt_get_result')) {
            return mysqli_stmt_get_result($stmt); // mysqli_result
        } else {
            // fallback: return array of rows
            $meta = mysqli_stmt_result_metadata($stmt);
            if (!$meta) return false;
            $fields = [];
            $row = [];
            $out = [];
            while ($field = mysqli_fetch_field($meta)) {
                $fields[] = &$row[$field->name];
            }
            call_user_func_array([$stmt, 'bind_result'], $fields);
            while (mysqli_stmt_fetch($stmt)) {
                $copy = [];
                foreach ($row as $k => $v) $copy[$k] = $v;
                $out[] = $copy;
            }
            return $out;
        }
    };

    // helper: fetch rows out of $res (mysqli_result or array)
    $fetch_rows = function ($res) {
        $rows = [];
        if ($res === false) return $rows;
        if (is_array($res)) {
            // fallback path already returned array of rows
            $rows = $res;
        } else {
            // mysqli_result path
            if ($res && mysqli_num_rows($res)) {
                while ($r = mysqli_fetch_assoc($res)) {
                    $rows[] = $r;
                }
            }
        }
        return $rows;
    };

    // helper: normalize a fetched client/booking to a single associative array
    $normalize_single = function ($item) {
        if (is_array($item)) {
            // if it's an array like [0 => [...]] unwrap it
            if (isset($item[0]) && is_array($item[0]) && isset($item[0]['id'])) {
                return $item[0];
            }
            // if it's a numeric-indexed list where first element looks like a row, unwrap
            $keys = array_keys($item);
            if (!empty($keys) && is_int($keys[0]) && isset($item[$keys[0]]['id'])) {
                return $item[$keys[0]];
            }
        }
        return $item;
    };

    // MODE = id: return single enriched row (assoc)
    if ($mode === 'id') {
        $sql = "SELECT * FROM {$table} WHERE id = ? LIMIT 1";
        $res = $prepare_and_get($sql, $value);
        if ($res === false) return array();

        $rows = $fetch_rows($res);
        if (empty($rows)) return array();
        $row = $rows[0];

        // Attach client (single) & booking (single, normalized)
        $row['client'] = (!empty($row['client_id']) && function_exists('GetCustomerById')) ? GetCustomerById(intval($row['client_id'])) : null;
        $rawBooking = (!empty($row['booking_id']) && function_exists('GetBooking')) ? GetBooking(intval($row['booking_id'])) : null;
        $row['booking'] = $normalize_single($rawBooking);

        return $row;
    }

    // MODE = client_id or booking_id: return numeric array of enriched rows
    if ($mode === 'client_id' || $mode === 'booking_id') {
        $col = ($mode === 'client_id') ? 'client_id' : 'booking_id';
        $sql = "SELECT * FROM {$table} WHERE {$col} = ?";
        $res = $prepare_and_get($sql, $value);
        if ($res === false) return array();

        $rows = $fetch_rows($res);
        if (empty($rows)) return [];

        // caches for lookups (avoid repeated calls)
        $client_cache = [];
        $booking_cache = [];

        // prefetch based on mode when helpful
        if ($mode === 'client_id' && function_exists('GetCustomerById')) {
            $client_cache[$value] = GetCustomerById($value);
        }
        if ($mode === 'booking_id' && function_exists('GetBooking')) {
            $booking_cache[$value] = $normalize_single(GetBooking($value));
        }

        $enriched = [];
        foreach ($rows as $r) {
            // ensure we have numeric ids
            $cid = !empty($r['client_id']) ? intval($r['client_id']) : null;
            $bid = !empty($r['booking_id']) ? intval($r['booking_id']) : null;

            // fetch client (cached)
            if ($cid !== null) {
                if (!array_key_exists($cid, $client_cache)) {
                    $client_cache[$cid] = function_exists('GetCustomerById') ? GetCustomerById($cid) : null;
                }
                $client = $client_cache[$cid];
            } else {
                $client = null;
            }

            // fetch booking (cached and normalized)
            if ($bid !== null) {
                if (!array_key_exists($bid, $booking_cache)) {
                    $raw = function_exists('GetBooking') ? GetBooking($bid) : null;
                    $booking_cache[$bid] = $normalize_single($raw);
                }
                $booking = $booking_cache[$bid];
            } else {
                $booking = null;
            }

            // attach into the row
            $r['client'] = $client;
            $r['booking'] = $booking;

            $enriched[] = $r;
        }

        return $enriched;
    }

    // unsupported mode
    return array();
}


function GetProjectById($id) {
	global $wo, $sqlConnect;

	if ($wo['loggedin'] == false) {
		return false;
	}
	if (empty($id) || !is_numeric($id)) {
		return array();
	}
	$id        = Wo_Secure($id);
	$query_one = " SELECT * FROM " . T_PROJECTS . " WHERE id = " . $id;
	$query     = mysqli_query($sqlConnect, $query_one);
	if (mysqli_num_rows($query)) {
		$data = array();
		$i = 0;
		while ($fetched_data = mysqli_fetch_assoc($query)) {
			$i++;
			$selected = ($i == 1) ? 'selected' : '';
			$fetched_data['selected'] = $selected;

			$data = $fetched_data;
		}
		return $data;
	}
	return array();
}

function GetPurchaseByClientId($id) {
	global $wo, $sqlConnect;
	if ($wo['loggedin'] == false) {
		return false;
	}
	if (empty($id) || !is_numeric($id)) {
		return array();
	}
	$id        = Wo_Secure($id);
	$query_one = " SELECT * FROM " . T_PURCHASE . " WHERE customer_id = " . $id;
	$query     = mysqli_query($sqlConnect, $query_one);
	if (mysqli_num_rows($query)) {
		$data = array();
		while ($fetched_data = mysqli_fetch_assoc($query)) {
			$data[] = $fetched_data;
		}
		return $data;
	}
	return array();
}

function GetPurchaseById($id) {
	global $wo, $sqlConnect;
	if ($wo['loggedin'] == false) {
		return false;
	}
	if (empty($id) || !is_numeric($id)) {
		return array();
	}
	$id        = Wo_Secure($id);
	$query_one = " SELECT * FROM " . T_PURCHASE . " WHERE id = " . $id;
	$query     = mysqli_query($sqlConnect, $query_one);
	if (mysqli_num_rows($query)) {
		$data = array();
		while ($fetched_data = mysqli_fetch_assoc($query)) {
			$data = $fetched_data;
		}
		return $data;
	}
	return array();
}

function GetInvoiceById($id) {
	global $wo, $sqlConnect;
	if ($wo['loggedin'] == false) {
		return false;
	}
	if (empty($id) || !is_numeric($id)) {
		return array();
	}
	$id        = Wo_Secure($id);
	$query_one = " SELECT * FROM " . T_INVOICE . " WHERE inv_id = " . $id;
	$query     = mysqli_query($sqlConnect, $query_one);
	if (mysqli_num_rows($query)) {
		$data = array();
		while ($fetched_data = mysqli_fetch_assoc($query)) {
			$data = $fetched_data;
		}
		return $data;
	}
	return array();
}
function GetInvoicesByCustomerId($id) {
	global $wo, $sqlConnect;
	if ($wo['loggedin'] == false) {
		return false;
	}
	if (empty($id) || !is_numeric($id)) {
		return array();
	}
	$id        = Wo_Secure($id);
	$query_one = " SELECT * FROM " . T_INVOICE . " WHERE customer_id = " . $id;
	$query     = mysqli_query($sqlConnect, $query_one);
	if (mysqli_num_rows($query)) {
		$data = array();
		while ($fetched_data = mysqli_fetch_assoc($query)) {
			$data = $fetched_data;
		}
		return $data;
	}
	return array();
}
function GetInvoicesByPurchaseId($id) {
	global $wo, $sqlConnect;
	if ($wo['loggedin'] == false) {
		return false;
	}
	if (empty($id) || !is_numeric($id)) {
		return array();
	}
	$id        = Wo_Secure($id);
	$query_one = " SELECT * FROM " . T_INVOICE . " WHERE purchase_id = " . $id;
	$query     = mysqli_query($sqlConnect, $query_one);
	if (mysqli_num_rows($query)) {
		$data = array();
		while ($fetched_data = mysqli_fetch_assoc($query)) {
			$data = $fetched_data;
		}
		return $data;
	}
	return array();
}
// Function to extract numbers from a string
function extractNumbers($string) {
    preg_match_all('/\d+/', $string, $matches);
    return implode(', ', $matches[0]);
}
function get_plot_numbers_from_string($plotString) {
    preg_match_all('/#(\d+)/', $plotString, $matches);
    return $matches[1];
}

function get_grossSalary($user_id, $month_start, $month_end) {
    global $db; // Make sure $db is accessible within this function

    $startDateTime = new DateTime($month_start);
    $endDateTime = new DateTime($month_end);
	
    $startDateTimeFormatted = $startDateTime->format('Y-m-d H:i:s');
    $endDateTimeFormatted = $endDateTime->format('Y-m-d H:i:s');

    $salary = $db->where('Badgenumber', $user_id)
        ->where('time', $endDateTimeFormatted, '<=')
        ->getValue(T_GROSS_SALARY, 'SUM(amount)');
	
    return ($salary) ? $salary : 0;	
}

function fetchAndProcessSalary($user, $month_start, $month_end, $i) {
    global $db;
    $result = array(); // Initialize as an empty array

    // Convert date strings to DateTime objects for comparison
    $startDateTime = new DateTime($month_start);
    $endDateTime = new DateTime($month_end);

    // Iterate through each date in the range
    $currentDateTime = clone $startDateTime;
    $total_present = 0;
    $total_late = 0;
    $total_late_hrs = 0;
    $total_absent = 0;
    $gross_salary_raw = get_grossSalary($user->user_id, $month_start, $month_end);
    $advanceData = $db->where('Badgenumber', $user->user_id)->where('time', $month_start)->getOne(T_ADVANCE_SALARY);
    $advance = (is_object($advanceData) && property_exists($advanceData, 'amount')) ? $advanceData->amount : 0;
    $total_days = $endDateTime->format('t');
    $is_working = 0;

    // Use DatePeriod to iterate through each day in the range
    $dateInterval = new DateInterval('P1D'); // 1 day interval
    $dateRange = new DatePeriod($startDateTime, $dateInterval, $endDateTime->modify('+1 day'));

    foreach ($dateRange as $currentDateTime) {
        $currentDate = $currentDateTime->format('Y-m-d');

        // Fetch attendance data for the current date
        $result_set = $db->where('USERID', $user->user_id)
            ->where('CHECKTIME', "$currentDate 00:00:00", '>=')
            ->where('CHECKTIME', "$currentDate 23:59:59", '<=')
            ->orderby('CHECKTIME', 'ASC')
            ->get('atten_in_out');
		
		
		// Check if $result_set is an array and not empty before trying to access its properties
		if (is_array($result_set) && !empty($result_set)) {
			// Assume the first item in the array contains the relevant data
			$firstResult = reset($result_set);

			// Check if $firstResult is an object and has 'USERID' property
			if (is_object($firstResult) && property_exists($firstResult, 'USERID') && $user->user_id == $firstResult->USERID) {
				$is_working = count($result_set);
				// If there is no attendance data, consider it as a working day
				if (count($result_set) == 0) {
					$is_working = 1;
				}
			}
		}

        // Process salary data for the current date and increment counters
        $dataResult = processSalaryData($user, $currentDate, $result_set);
		$status = $dataResult['status'];
		
        if ($status == 'Present') {
            // $total_present++;
        } elseif ($status == 'Late') {
            $total_late++;
			$total_late_hrs += $dataResult['late_count'];
        } else {
            $total_absent++;
			$total_late_hrs += $dataResult['late_count'];
        }
    }

    // Deduct 1 day salary for every 3 late days
    $late_deduction = floor($total_late / 3);

    // Deduct 1 day salary for every absent day
    $absent_deduction = $total_absent;

    // Calculate total deduction
    $total_deduc = $late_deduction + $absent_deduction;

    // Calculate working days
    $workingDays = $total_days - $total_deduc;

    // Calculate payable amount
    $payable_raw = $gross_salary_raw - $advance - ($total_deduc * ($gross_salary_raw / $total_days));
	
	if ($user->exclude_attendance == true) {
		$is_working = 1;
	}
    if ($is_working <= 0) {
        $workingDays = 0;
        $total_late = 0;
        $total_late_hrs = 0;
        $total_deduc = 0;
        $gross_salary_raw = 0;
        $advance = 0;
        $payable_raw = 0;
    }

	if ($total_late_hrs) {
		$hours = floor($total_late_hrs / 60);
		$minutes = $total_late_hrs % 60;
		$lateCountFormatted = sprintf('%2dh:%02dm', $hours, $minutes);
	} else {
		$lateCountFormatted = '0h:00m';
	}
	
	
	if ((Wo_IsAdmin() || Wo_IsModerator() || check_permission('manage-advance-salary'))) {
		$manage_advance = '<a href="javascript:;" class="text-success" data-bs-toggle="tooltip" data-bs-placement="bottom"
                    title="" data-bs-original-title="Manage Advance" aria-label="Manage Advance" onclick="manage_advance(`' . $month_start . '`,`' . $user->user_id . '`)">
                    <i class="fadeIn animated bx bx-gift"></i>
                </a>';
	} else {
		$manage_advance = '';
	}
	
    $result[] = array(
        'sl' => $i,
        'name' => '<img src="/' . $user->avatar . '" class="user-img" style=" width: 24px; height: 24px; border-radius: 35px; margin-right: 8px; ">' . cleanName($user->first_name) . ' ' . $user->last_name,
        'designation' => $user->designation,
        // 'working_day' => 0,
        'working_day' => $workingDays,
        'late' => $total_late,
        // 'late' => 0,
        'absent' => $absent_deduction,
        'gross_salary' => '৳' . number_format($gross_salary_raw),
        'advance' => '৳' . number_format($advance),
        'payable' => '৳' . number_format($payable_raw),
        // 'payable' => '--',
        'signature' => $late_deduction,
        'action' => $manage_advance,
        'r_working_day' => $workingDays,
        'r_late' => $total_late,
        // 'r_late' => 0,
        'r_late_count' => $total_late_hrs,
        'late_count' => $lateCountFormatted,
        'r_absent' => $absent_deduction,
        'r_gross_salary' => $gross_salary_raw,
        'r_advance' => $advance,
        'r_payable' => $payable_raw,
    );
	
    return $result;
}

function processSalaryData($user, $currentDate, $result_set) {
    global $db;
    $in_times = array();
    $out_times = array();
    $status = '';

    foreach ($result_set as $row) {
        if ($row->CHECKTYPE == 'i' || $row->CHECKTYPE == 'I') {
            $in_times[] = strtotime(substr($row->CHECKTIME, 11));
        } elseif ($row->CHECKTYPE == 'o' || $row->CHECKTYPE == 'O') {
            $out_times[] = strtotime(substr($row->CHECKTIME, 11));
        }
    }

    $in_time = $in_times ? min($in_times) : null;
    $out_time = $out_times ? max($out_times) : null;

    // Check if it's a holiday
    $is_holiday = processHolidaydata($currentDate);

    $status = getStatusBasedOnTime($in_time, $out_time, $currentDate);

    $get_reason = $db->where('Badgenumber', $user->user_id)
        ->where('date', $currentDate)
        ->getOne('atten_reason');
		
	$is_leave = false;
	$is_joining = false;
	if ($currentDate == $user->joining_date) {
		$is_leave = $is_joining = true;
	} else {
		// Define the specific date range
		$specific_date_start = strtotime($currentDate . ' 00:00:00'); // Start of the specific date
		$specific_date_end = strtotime($currentDate . ' 23:59:59');   // End of the specific date

		// Build the query with placeholders for security
		$get_leaves = $db->where('(leave_from <= ? AND leave_to >= ?)', [$specific_date_end, $specific_date_start])
						 ->where('is_approved', '1')
						 ->where('is_paid', '1')
						 ->where('user_id', $user->user_id)
						 ->get('crm_leaves');
				 
		if ($get_leaves) {
			$is_leave = true;
		} else {
			$is_leave = false;
		}
	}
	
	if ($currentDate >= $user->joining_date) {
        if (!empty($is_holiday->name) || !empty($get_reason) || $is_leave == true) {
            $status = 'Present';
        }
	}
	
	// Calculate the late count
	$lateCount = ($status === 'Late' || $status === 'Absent') ? max(0, round(($in_time - strtotime('09:45:59')) / 60)) : 0;
	
	if ($currentDate >= $user->joining_date) {
	    $status = 'Present';
		$is_leave = true;
	}
	
	if (empty($get_leaves) && empty($get_reason) && empty($is_holiday->name) && $user->exclude_attendance == 1) {
		
		if ($user->exclude_attendance == 1) {
			
			$get_leaves_unpaid = $db->where('(leave_from <= ? AND leave_to >= ?)', [$specific_date_end, $specific_date_start])
							 ->where('is_approved', '1')
							 ->where('is_paid', '0')
							 ->where('user_id', $user->user_id)
							 ->get('crm_leaves');
			if (!empty($get_leaves_unpaid)) {
				$output = array(
					'status' => 'Absent',
					'late_count' => 0
				);
			} else {
				$output = array(
					'status' => 'Present',
					'late_count' => 0
				);
			}
			
		} else {
			$output = array(
				'status' => 'Present',
				'late_count' => 0
			);
		}
	} else {
		$output = array(
			'status' => $status,
			'late_count' => $lateCount
		);
	}
	
    return $output;
}

function getStatusBasedOnTime($in_time, $out_time, $currentDate) {
    if ($in_time) {
        if ($in_time <= strtotime('09:45:59')) {
            return 'Present';
        // } elseif ($in_time >= strtotime('09:46:00') && $in_time <= strtotime('12:00:59')) {
        // } elseif ($in_time >= strtotime('09:46:00')) {
        } else {
            return 'Late';
            // return 'Absent';
        }
    } else {
        return 'Absent';
    }
}

// Function to fetch and process attendance data
function fetchAndProcessAttendance($user, $start_date, $end_date) {
	global $db;
	$result = array(); // Initialize as an empty array

	// Convert date strings to DateTime objects for comparison
	$startDateTime = new DateTime($start_date);
	$endDateTime = new DateTime($end_date);

	// Iterate through each date in the range
	$currentDateTime = clone $startDateTime;
	while ($currentDateTime <= $endDateTime) {
		$currentDate = $currentDateTime->format('Y-m-d');

		// Fetch attendance data for the current date
		$result_set = $db->where('USERID', $user->user_id)
			->where('CHECKTIME', "$currentDate 00:00:00", '>=')
			->where('CHECKTIME', "$currentDate 23:59:59", '<=')
			->orderby('CHECKTIME', 'ASC')
			->get('atten_in_out');

		// Process attendance data for the current date
		$result[] = processAttendanceData($user, $currentDate, $result_set);

		// Move to the next day
		$currentDateTime->modify('+1 day');
	}

	return $result;
}

function processAttendanceData($user, $currentDate, $result_set) {
	global $db;
	$in_times = array(); // An array to store all in times
	$out_times = array(); // An array to store all out times
	$status = '';

	// Iterate through the results to find in and out times
	foreach ($result_set as $row) {
		if ($row->CHECKTYPE == 'i' || $row->CHECKTYPE == 'I') {
			$in_times[] = strtotime(substr($row->CHECKTIME, 11));
		} elseif ($row->CHECKTYPE == 'o' || $row->CHECKTYPE == 'O') {
			$out_times[] = strtotime(substr($row->CHECKTIME, 11));
		}
	}

	// Get the lowest in time
	$in_time = $in_times ? min($in_times) : null;

	// Get the highest out time
	$out_time = $out_times ? max($out_times) : null;

	// Check if it's a holiday
	$is_holiday = processHolidaydata($currentDate);

	if ($in_time && $out_time) {
		// Check if it's Late Present
		if ($in_time <= strtotime('09:40:59')) {
			$status = 'Present';
		// } elseif ($in_time >= strtotime('09:41:00') && $in_time <= strtotime('12:00:59')) {
		} elseif ($in_time >= strtotime('09:41:00')) {
			$status = 'Late';
		} else {
			$status = 'Absent';
		}
	} else {
		$status = (isToday($currentDate) && strtotime('now') <= strtotime('23:59:59')) ? '' : 'Absent';
		if (isToday($currentDate) && strtotime('now') <= strtotime('23:59:59')) {
			if ($in_time <= strtotime('09:40:59')) {
				$status = ' ';
			// } elseif ($in_time >= strtotime('09:41:00') && $in_time <= strtotime('12:00:59')) {
			} elseif ($in_time >= strtotime('09:41:00')) {
				$status = 'Late';
			} else {
				$status = 'Absent';
			}
		} else {
			if (strtotime($currentDate) > strtotime(date('Y-m-d'))) {
				$status = ' ';
			} else {
				if ($in_time >= strtotime('09:41:00')) {
					$status = 'Late';
				} else {
					$status = ' ';
					if (empty($in_time)) {
						$status = 'Absent';
					} else if ($in_time >= strtotime('12:00:59')) {
						$status = 'Absent';
					}
				}
			}
		}
	}

	// Calculate the late count
	$lateCount = ($status === 'Late' || $status === 'Absent') ? max(0, round(($in_time - strtotime('09:40:00')) / 60)) : 0;

	// Format the late count
	if ($lateCount) {
		$hours = floor($lateCount / 60);
		$minutes = $lateCount % 60;
		$lateCountFormatted = sprintf('%2d:%02d', $hours, $minutes);
	} else {
		$lateCountFormatted = ' ';
	}
	$show_action = true;
	$auto_present = false;
	if ($currentDate == $user->joining_date) {
		$get_reason = new stdClass();
		$get_reason->reason = 'Joining Date';
		$get_reason->text = 'Joining Date';
		$get_reason->Badgenumber = $user->user_id;
		$get_reason->date = $currentDate;
	} else {
		$get_leaves = $db->where('user_id', $user->user_id)
						 ->where('leave_from', strtotime($currentDate), '<=')
						 ->where('leave_to', strtotime($currentDate), '>=')
						 ->getOne('crm_leaves');


		if (!empty($get_leaves)) {
			// Create an stdClass object for the leave case
			$get_reason = new stdClass();
			
		    if ($get_leaves->is_approved == '1') {
		        
    			if ($get_leaves->is_paid == 1) {
    				$status_text = ' (Paid)';
    			    $get_reason->class = 'reason paid';
    			} else if ($get_leaves->is_paid == 0) {
    				$status_text = ' (Unpaid)';
    			    $get_reason->class = 'reason unpaid';
    			} else {
    				$status_text = '';
    			}
    			$show_action = false;
    			$get_reason->reason = $get_leaves->type . $status_text;
    			$get_reason->text = $get_leaves->reason;
    			$get_reason->Badgenumber = $user->user_id;
    			$get_reason->date = $currentDate;
    			
		    } else {
		        $show_action = false;
    			$get_reason->reason = 'Applied (' . $get_leaves->type . ')';
    			$get_reason->text = 'Applied leave app. for - ' . $get_leaves->reason;
    			$get_reason->Badgenumber = $user->user_id;
    			$get_reason->date = $currentDate;
    			$get_reason->class = 'reason pending';
		    }
			
		} else {
			$get_reason = $db->where('Badgenumber', $user->user_id)->where('date', $currentDate)->getOne('atten_reason');
		
    		if (!empty($get_reason)) {
    			$get_reason->class = 'reason';
    		}	
		}
		
		if (empty($get_leaves) && empty($get_reason) && $user->exclude_attendance == 1) {
			$auto_present = true;
		}
	}
	
	// Construct and return the result for the current date
	$result = array(
		'date' => $currentDate,
		'day' => date('l', strtotime($currentDate)),
		'name' => '<img src="/' . $user->avatar . '" class="user-img" style=" width: 24px; height: 24px; border-radius: 35px; margin-right: 8px; ">' . $user->first_name . ' ' . $user->last_name,
		'designation' => $user->designation,
		'in_time' => $in_time ? date('h:i A', $in_time) : '',
		'out_time' => $out_time ? date('h:i A', $out_time) : '',
		'late_count' => $lateCountFormatted,
		'class' => !empty($get_reason) && isset($get_reason->class) ? $get_reason->class : $user->user_id . '_' . str_replace('-', '_', $currentDate) . ' ' . (isset($is_holiday->key) ? 'weekend' : str_replace(' ', '_', strtolower($status))),
		'actions' => '', // Replace 'Your_Action_Value_Here' with the actual action value
	);
	// Only print $status if it's not a holiday
	if (empty($is_holiday->name)) {
		if (empty($get_reason)) {
			$result['status'] = $status;
		} else {
			if ($get_reason->text) {
				$rtext = $get_reason->text;
			} else {
				$rtext = 'Warning! Reson description is empty!';
			}
			$result['status'] = htmlspecialchars($get_reason->reason);
			$result['rtext'] = $rtext;
		}
		if ((Wo_IsAdmin() || Wo_IsModerator() || check_permission('manage-attandance')) && $get_reason->reason != 'Joining Date' && $show_action == true) {
			$result['actions'] = '<td class="hide_in_print"><svg class="reason_btn" onclick="add_reason_modal(`' . $currentDate . '`,`' . $user->user_id . '`)" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 18 18"><path d="M15.364,2.639a9,9,0,0,0-13.38,12,3.084,3.084,0,0,1-1.3,1.412.955.955,0,0,0,.276,1.8,4.653,4.653,0,0,0,.731.055,5.688,5.688,0,0,0,3.114-.945A9,9,0,0,0,15.364,2.639Zm-.889,11.832a7.747,7.747,0,0,1-9.392,1.206.628.628,0,0,0-.706.044,4.413,4.413,0,0,1-2.327.912A5.271,5.271,0,0,0,3.307,14.8a.623.623,0,0,0-.106-.669,7.743,7.743,0,1,1,11.274.345Zm-1.746-6.1H5.274a.631.631,0,1,0,0,1.261h7.455a.631.631,0,1,0,0-1.261Zm0-2.555H5.274a.633.633,0,1,0,0,1.265h7.455a.633.633,0,0,0,0-1.265Zm0,5.11H5.274a.63.63,0,1,0,0,1.26h7.455a.63.63,0,1,0,0-1.26Z" transform="translate(0)" style="fill:currentColor"></path></svg></td>';
		}
	} else {
		$result['status'] = $is_holiday->name;
		$result['class'] = 'weekend';
	}
	if ($auto_present == true && empty($is_holiday->name)) {
		$result['class'] = 'present';
		$result['status'] = 'Auto Present';
	}

    //Override the output class
	if ($currentDate == $user->joining_date) {
		$result['class'] = 'reason';
	}
	
	return $result;
}


// Function to check if a given date is today
function isToday($date) {
	return date('Y-m-d') === $date;
}

function ordinalNumber($number) {
    if ($number % 100 >= 11 && $number % 100 <= 13) {
        $suffix = 'th';
    } else {
        switch ($number % 10) {
            case 1:
                $suffix = 'st';
                break;
            case 2:
                $suffix = 'nd';
                break;
            case 3:
                $suffix = 'rd';
                break;
            default:
                $suffix = 'th';
        }
    }
    return $number . $suffix;
}

// Function to import leads from a CSV file
function convertToTimestamp($dateString) {
	// Define possible formats
	$formats = [
		'd/m/Y, g:i', // DD/MM/YYYY, H:i (12-hour format)
		'm/d/Y, g:i', // MM/DD/YYYY, H:i (12-hour format)
		'm/d/Y',      // MM/DD/YYYY (no time part)
		'd/m/Y'       // DD/MM/YYYY (no time part)
	];

	foreach ($formats as $format) {
		// Create a DateTime object based on the format
		$date = DateTime::createFromFormat($format, $dateString);

		// Check if the date was parsed successfully and matches the original string
		if ($date && $date->format($format) === $dateString) {
			return $date->getTimestamp();
		}
	}
	
	return false; // Return false if no format matched
}
function correctNumber($number) {
    // Convert the number to a string
    $numberStr = (string)$number;
	
    // Check if the length of the number is 10
    if (strlen($numberStr) == 10 && substr($number, 0, 1) == '1') {
        // Prepend a leading zero
		return '0' . $numberStr;
    } else {
		return $number;
	}
}
// Function to process holiday data for a specific date
function processHolidaydata($currentDate) {
    // Define holidays
    $year = date('Y', strtotime($currentDate));
    $holidays = array(
        'friday' => 'weekend',
        'eid_ajha' => '2024-06-29',
        '16_december' => $year . '-12-16',
        'christmas' => $year . '-12-25',
        'election' => array('2024-01-06', '2024-01-07'),
        '21st_february' => $year . '-02-21',
        'shab_e_barat' => '2024-02-26',
        'independence_day' => $year . '-03-26',
        'pôhela_boishakh' => '2024-04-14',
        'international_workers_day' => $year . '-05-01',
        'eid-E-Miladunnabi' => '2024-09-16',
        'sabe_barat' => '2025-02-15',
        'July_Andolonon' => '2025-08-05',
        'government-holiday' => array('2024-08-04', '2024-08-05', '2024-08-06', '2024-08-07', '2024-08-08'),
        'EID-UL-FITAR' => array('2025-03-29', '2025-03-30', '2025-03-31', '2025-04-01', '2025-04-02', '2025-04-03', '2025-04-04', '2025-04-05'),
        'EID-UL-AZHA' => array('2025-06-05', '2025-06-06', '2025-06-07', '2025-06-08', '2025-06-09', '2025-06-10', '2025-06-11', '2025-06-12', '2025-06-13', '2025-06-14'),
        'durga-puja' => array('2024-10-10', '2024-10-13'),
        'Janmashtami' => '2024-08-26',
        'Pohela_Boishakh' => '2025-04-14',
        'Buddha_Purnima' => '2025-05-11',
        'Ashura' => '2025-07-06',
        'Janmashtami' => '2025-08-16',
    );

    // Convert current date to Y-m-d format for consistency
    $currentDate = date('Y-m-d', strtotime($currentDate));

    // Check if the current date is in any range or list of specific holidays
    foreach ($holidays as $holiday_key => $holiday_dates) {
        if (is_array($holiday_dates)) {
            // Handle multiple specific dates or date ranges
            foreach ($holiday_dates as $holiday_date) {
                if (strpos($holiday_date, 'to') !== false) {
                    // Handle date ranges
                    list($start_date, $end_date) = explode('to', $holiday_date);
                    $start_date = date('Y-m-d', strtotime($start_date));
                    $end_date = date('Y-m-d', strtotime($end_date));
                    if ($currentDate >= $start_date && $currentDate <= $end_date) {
                        $holiday_name = str_replace('_', ' ', $holiday_key);
                        $holiday_name = ucwords($holiday_name);
                        return (object) array('key' => $holiday_key, 'name' => $holiday_name);
                    }
                } else {
                    // Handle single specific dates
                    if ($currentDate === $holiday_date) {
                        $holiday_name = str_replace('_', ' ', $holiday_key);
                        $holiday_name = ucwords($holiday_name);
                        return (object) array('key' => $holiday_key, 'name' => $holiday_name);
                    }
                }
            }
        } else {
            // Handle single specific holiday date
            if ($currentDate === $holiday_dates) {
                $holiday_name = str_replace('_', ' ', $holiday_key);
                $holiday_name = ucwords($holiday_name);
                return (object) array('key' => $holiday_key, 'name' => $holiday_name);
            }
        }
    }

    // Check if the current date is a Friday
    $dayOfWeek = date('l', strtotime($currentDate));
    if ($dayOfWeek === 'Friday') {
        return (object) array('key' => 'weekend', 'name' => 'Weekend');
    }

    // If not a holiday or Friday, return null
    return null;
}
function calculateDurationInDays($startTimestamp, $endTimestamp) {
    $dateFrom = (new DateTime())->setTimestamp($startTimestamp)->setTime(0,0);
    $dateTo   = (new DateTime())->setTimestamp($endTimestamp)->setTime(0,0);

    $interval = new DateInterval('P1D');
    $period   = new DatePeriod($dateFrom, $interval, $dateTo->modify('+1 day'));

    $totalDays = 0;
    foreach ($period as $date) {
        $ymd = $date->format('Y-m-d');
        $is_holiday = processHolidaydata($ymd);
        $skip = !empty($is_holiday->name) ? ' (holiday)' : '';
        if (empty($is_holiday->name)) {
            $totalDays++;
        }
    }

    return $totalDays;
}
function getAdjustedResumeDate($timestamp) {
    // Create a DateTime object from the given timestamp
    $date = new DateTime();
    $date->setTimestamp($timestamp);

    // Add one day to the initial date
    $date->modify('+1 day');

    // Loop until a valid (non-Friday, non-holiday) date is found
    while ($date->format('N') == 5 || processHolidaydata($date->format('Y-m-d'))) {
        // If it's Friday or a holiday, move to the next day
        $date->modify('+1 day');
    }

    // Return the formatted date as 'd-m-Y'
    return $date->format('d-m-Y');
}

function cleanName($name) {
    // Define the patterns to be removed
    $patterns = [
        '/^\bmd\.?\s*/i', // Matches "md", "md.", "Md", "Md.", "MD", "MD."
        '/^\bMD\.?\s*/i'  // Matches "MD", "MD.", "Md", "Md.", "MD", "MD."
    ];

    // Clean the name by removing the unwanted prefixes
    $cleanedName = preg_replace($patterns, '', $name);

    // Trim any leading or trailing whitespace
    $cleanedName = trim($cleanedName);

    return $cleanedName;
}

function short_name($text) {
    // Define the mapping of phrases to their replacements
    $matches = array(
        'Senior Designer' => 'Sr. Designer',
        'Assistant General Manager' => 'A.G.M',
        'Senior Assistant Manager' => 'Sr. Asst. M.',
        'Assistant Manager' => 'Asst. M.',
        'Senior Executive' => 'Sr. Executive',
        'Deputy Manager' => 'D.M.',
        'Md. ' => '' // Ensure there's a trailing space if you want to remove only the phrase "Md. "
    );
    
    // Loop through each key-value pair in the $matches array
    foreach ($matches as $search => $replace) {
        // Remove the current $match from the $text
        $text = str_replace($search, $replace, $text);
    }
    
    // Return the modified text
    return $text;
}
// Function to validate and format the mobile number
function formatContactNumber($contact) {
    // Remove any non-numeric characters
    $contact = preg_replace('/\D/', '', $contact);

    // Check the length and format accordingly
    if (strlen($contact) === 11 && $contact[0] === '0') {
        // Format: 017XXXXXXXX to 880174XXXXXXXX
        return '880' . substr($contact, 1); // Remove the leading '0'
    } elseif (strlen($contact) === 10 && $contact[0] === '1') {
        // Format: 1XXXXXXXXX to 8801XXXXXXXX
        return '880' . $contact;
    } elseif (strlen($contact) === 13 && strpos($contact, '880') === 0) {
        // Already in the correct format
        return $contact;
    } else {
        return null; // Invalid number
    }
}

ini_set('display_errors', 1);
ini_set('display_startup_errors', 0);
error_reporting(1);
$sms_api_elitbuzz = 'C200794166e165ca0470b2.11927812';
$sms_api_iglWeb = '44517100458726701710045872';

function sms_delivery_iglweb($sms_id) {
    global $sms_api_iglWeb;

    // Ensure API key is set
    if (!isset($sms_api_iglWeb)) {
        return ["error" => "API key is not set."];
    }

    $url = "http://sms.felnadma.com/api/v1/getDeliveryReport?api_key=$sms_api_iglWeb&sms_id=$sms_id"; // IGL Web URL

    // Fetch the API response
    $response = @file_get_contents($url);
    if ($response === FALSE) {
        return ["error" => "Failed to fetch response from IGL Web API."];
    }

    // Decode JSON response for IGL Web
    $result = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return ["error" => "Failed to decode JSON response from IGL Web: " . json_last_error_msg()];
    }

    return $result; // Return the structured result for further processing
}
function sms_delivery($sms_id) {
    global $sms_api_elitbuzz;

    // Ensure API key is set
    if (!isset($sms_api_elitbuzz)) {
        return ["error" => "API key is not set."];
    }

    $url = "https://msg.elitbuzz-bd.com/miscapi/$sms_api_elitbuzz/getDLRRep/$sms_id";

    // Fetch the API response
    $response = @file_get_contents($url);
    if ($response === FALSE) {
        return ["error" => "Failed to fetch response from API."];
    }

    // Decode JSON response
    $result = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return ["error" => "Failed to decode JSON response: " . json_last_error_msg()];
    }

    // Return the result
    return $result;
}

function sms_update_report() {
    global $db;

    // Get the current time for comparison
    $currentTimestamp = time();
    
    // Calculate the timestamp for two minutes ago
    $twoMinutesAgo = $currentTimestamp + (2 * 60);

    // Get SMS records that are 'Sending', have been tried less than 3 times, and where last_tried is less than or equal to two minutes ago
    $smsRecords = $db->where('status', 'Sending') // Not equal to 'Delivered'
                     ->where('tried', 3, '<')
                     ->where('last_tried', $twoMinutesAgo, '<=')
                     ->get(T_SMS, 5);

    // Log the SQL query for debugging (optional)
    error_log($db->getLastQuery());

    foreach ($smsRecords as $smsRecord) {
        // Check the vendor based on sms_vendor field
        $isElitbuzz = strpos($smsRecord->sms_vendor, 'elitbuzz') !== false;

        // Get delivery details based on vendor
        $deliveries = $isElitbuzz ? sms_delivery($smsRecord->sms_id) : sms_delivery_iglweb($smsRecord->sms_id);

        // Process the delivery response
        if (isset($deliveries['error'])) {
            // Log the error if there's an issue with delivery status
            error_log("Delivery check error for sms_id: " . $smsRecord->sms_id . " - " . $deliveries['error']);
            continue; // Skip to the next record
        }
        
        // Prepare the data array for update
        $data_array = [
            'tried' => $smsRecord->tried + 1, // Increment the tried count
            'last_tried' => $currentTimestamp // Update the last tried timestamp to current time
        ];

        if ($isElitbuzz) {
			if ($data_array['status'] > 2 && $deliveries[0]['sms_status_str'] != 'Delivered') {
				$status = 'Failed';
			} else {
				$status = $deliveries[0]['sms_status_str'] ?? 'Failed'; // Assuming it's an array
			}
            $data_array['status'] = $status;
            $data_array['cost'] = $deliveries[0]['charges_per_sms'] ?? '0'; // Set default cost
        } else {
            $data_array['status'] = $deliveries['status'] ?? 'Failed';
            $data_array['cost'] = $deliveries['charges_per_sms'] ?? ''; // Add cost if available
        }
        
        // Update SMS record in the database
        if (!$db->where('id', $smsRecord->id)->update(T_SMS, $data_array)) {
            error_log("Failed to update SMS status for sms_id: " . $smsRecord->sms_id);
        }
    }
}

function sms_send($data) {
    global $sms_api_elitbuzz, $sms_api_iglWeb, $wo;

    // Determine the SMS vendor
    $sms_vendor = $data['sms_vendor'] ?? 'elitbuzz';

    // Balance checks for each vendor
    if ($sms_vendor === 'elitbuzz' && $wo['config']['elitbuzz_balance'] <= 1) {
        return 'Elitbuzz balance is low! Current Balance is: <strong class="text-black">৳' . $wo['config']['elitbuzz_balance'] . '</strong>';
    }

    if ($sms_vendor === 'iglWeb' && $wo['config']['iglweb_balance'] <= 1) {
        return 'IGL Web balance is low! Current Balance is: <strong class="text-black">৳' . $wo['config']['iglweb_balance'] . '</strong>';
    }

    // Prepare API URL and API key based on the vendor
    $url = '';
    $api_key = '';
    if ($sms_vendor === 'elitbuzz') {
        $url = "https://msg.elitbuzz-bd.com/smsapi";
        $api_key = $sms_api_elitbuzz;
    } elseif ($sms_vendor === 'iglWeb') {
        $url = "http://sms.felnadma.com/api/v1/send";
        $api_key = $sms_api_iglWeb;
    }

    // Ensure API key is set
    if (empty($api_key)) {
        return "Error: API key is not set for $sms_vendor.";
    }

    // Prepare the message and contacts
    if (empty($data['senderid'])) {
        $data['senderid'] = '38756'; // Default sender ID for elitbuzz
    }
    
    $data['msg'] = str_replace('<br>', "\n", $data['msg']); // Handle line breaks
    $contactsArray = array_map('trim', explode(',', $data['contacts'])); // Split contacts
    $contacts = ($sms_vendor === 'elitbuzz') ? implode('+', $contactsArray) : implode(',', $contactsArray); // Format contacts

    // Initialize cURL
    $ch = curl_init();

    // Prepare request based on vendor
    if ($sms_vendor === 'elitbuzz') {
        $params = [
            'api_key' => $api_key,
            'type' => 'text', // Adjust if sending Unicode
            'contacts' => $contacts,
            'senderid' => $data['senderid'],
            'msg' => $data['msg'],
        ];
        
        curl_setopt($ch, CURLOPT_URL, $url . '?' . http_build_query($params));
    } elseif ($sms_vendor === 'iglWeb') {
        $params = [
            'api_key' => $api_key,
            'contacts' => $contacts,
            'senderid' => $data['senderid'],
            'msg' => $data['msg'],
        ];

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
    }

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/x-www-form-urlencoded'
    ]);

    // Execute cURL request
    $response = curl_exec($ch);

    // Check for cURL errors
    if (curl_errno($ch)) {
        $error_msg = curl_error($ch);
        curl_close($ch);
        return "Error: cURL error - $error_msg";
    }

    // Check HTTP response code
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($http_code != 200) {
        curl_close($ch);
        return "Error: HTTP response code $http_code";
    }

    curl_close($ch);

    // Handle responses for each vendor
    if ($sms_vendor === 'elitbuzz') {
        if (strpos($response, 'SMS SUBMITTED: ID - ') !== false) {
            preg_match('/SMS SUBMITTED: ID - (\S+)/', $response, $matches);
            $sms_id = $matches[1] ?? null;
            return handle_sms_record($data, $sms_id, $sms_vendor);
        } else {
            return "Unexpected response from Elitbuzz: $response";
        }
    } elseif ($sms_vendor === 'iglWeb') {
        $responseData = json_decode($response);
		print_r($responseData);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return "Error: Failed to decode JSON response.";
        }
        if (isset($responseData->status) && $responseData->status === 'success') {
            return handle_sms_record($data, $responseData->sms_id ?? null, $sms_vendor);
        } else {
            return "Error from IGL Web: " . ($responseData->message ?? "Unexpected response.");
        }
    }

    return "Unexpected response: $response";
}

// Function to handle SMS record insertion and balance update
function handle_sms_record($data, $sms_id, $sms_vendor) {
    global $db, $wo;

    // Map sender ID if necessary
    $senderid_map = [
        '38714' => '8809601011151',
        '38756' => 'CIVIC PLOTS',
        '40042' => 'CIVIC',
        '01847431162' => '01847431162',
        'CIVIC LAND' => 'CIVIC LAND',
    ];

    $senderid = $senderid_map[$data['senderid']] ?? $data['senderid'];
    $contacts = explode('+', $data['contacts']);
    $inserted_ids = [];

    foreach ($contacts as $contact) {
        $contact = trim($contact);
        $data_array = [
            'sms_id' => trim($sms_id),
            'sms_vendor' => $sms_vendor,
            'senderid' => $senderid,
            'type' => $data['type'],
            'contacts' => formatContactNumber($contact),
            'msg' => $data['msg'],
            'time' => time(),
            'last_tried' => time(),
            'user_id' => $data['user_id'] ?? $wo['user']['user_id'],
            'status' => 'Sending',
            'cost' => ''
        ];
        $db->insert(T_SMS, $data_array);
    }

    // // Update current balance
    // $balance = sms_get_balance($sms_vendor);
    // if ($balance) {
        // $balance = str_replace('Your Balance is:BDT ', '', $balance);
        // $key = $sms_vendor === 'elitbuzz' ? 'elitbuzz_balance' : 'iglweb_balance';
        // Wo_SaveConfig($key, $balance);
    // }

    return "SMS sent successfully with ID: $sms_id";
}

function sms_get_balance($sms_vendor = 'elitbuzz') {
	Global $sms_api_elitbuzz, $sms_api_iglWeb;
	
    $api_keys = [
        'elitbuzz' => $sms_api_elitbuzz ?? null,
        'iglWeb' => $sms_api_iglWeb ?? null,
    ];

    if (!isset($api_keys[$sms_vendor])) {
        return "Error: API key is not set.";
    }

    switch ($sms_vendor) {
        case 'elitbuzz':
            $url = "https://msg.elitbuzz-bd.com/miscapi/{$api_keys['elitbuzz']}/getBalance";
            $context = stream_context_create(['http' => ['timeout' => 4]]);
            $response = @file_get_contents($url, false, $context);
            break;

        case 'iglWeb':
            $url = "http://sms.felnadma.com/api/v1/balance?api_key={$api_keys['iglWeb']}";
            $context = stream_context_create(['http' => ['timeout' => 4]]);
            $response = @file_get_contents($url, false, $context);
            $responseData = json_decode($response);
            return $responseData->balance ?? "Error: Unable to retrieve balance.";
    }

    if ($response === false) {
        return "Error: " . (error_get_last()['message'] ?? 'Unknown error occurred.');
    }

    return $response;
}

function lead_report($user_id, $date) {
    global $db;
    // Fetch the report for the specified user and date
    $report = $db->where('user_id', $user_id)
                 ->where('date', $date)
                 ->getOne(T_LEADS_REPORT);

    // Check if a report was found; return structured data or null
    return $report ?: null; // Return null if no report found
}

function maskPhoneNumber($phone_number) {
    // Remove non-numeric characters (including '+' and spaces)
    $phone_number = preg_replace('/\D/', '', $phone_number);

    // Check if the phone number has at least 10 digits (after cleaning)
    if (strlen($phone_number) >= 10) {
        // Identify the length of the number (if it's a country code format, it will be longer)
        $length = strlen($phone_number);

        // For numbers with a country code, we assume the country code is the first 1-3 digits (e.g., +880)
        // Extract first part, middle part, and last part
        $start = substr($phone_number, 0, 3);  // Keep the first 3 digits (country code or area code)
        $end = substr($phone_number, -3);     // Keep the last 4 digits

        // Mask the middle part
        $middle = substr($phone_number, 3, -3); // Get the middle part excluding the first 3 and last 4 digits

        // Mask the middle 6 digits (or fewer if the number is shorter)
        $masked_middle = str_repeat('*', min(strlen($middle), 6));

        // Combine the parts: start + masked middle + end
        return $start . $masked_middle . $end;
    } else {
        // If the number is too short, return it as is (you can handle this differently if needed)
        return $phone_number;
    }
}

// Function to normalize the 'created' field to a Unix timestamp
function normalizeCreatedDate($createdDate) {
	// First, try to parse using strtotime (works for most common date formats)
	$timestamp = strtotime($createdDate);

	// If strtotime fails, try to handle specific date formats
	if ($timestamp === false) {
		// Try parsing ISO 8601 format using DateTime
		$dateTime = DateTime::createFromFormat(DateTime::ATOM, $createdDate);
		if ($dateTime) {
			$timestamp = $dateTime->getTimestamp();
		}
	}

	// If we still don't have a valid timestamp, use the current time
	if ($timestamp === false) {
		$timestamp = time(); // Default to current time
	}

	return $timestamp;
}

function GetDeviceName($userAgent) {
    $userAgent = strtolower($userAgent);
    if (strpos($userAgent, 'edge') !== false) return 'Edge';
    if (strpos($userAgent, 'chrome') !== false) return 'Chrome';
    if (strpos($userAgent, 'firefox') !== false) return 'Firefox';
    if (strpos($userAgent, 'safari') !== false && strpos($userAgent, 'chrome') === false) return 'Safari';
    if (strpos($userAgent, 'opera') !== false || strpos($userAgent, 'opr/') !== false) return 'Opera';
    if (strpos($userAgent, 'trident') !== false || strpos($userAgent, 'msie') !== false) return 'IE';
    return 'Unknown';
}
function GetDeviceIcon($name) {
    switch ($name) {
        case 'Chrome': return '<i class="fa fa-chrome"></i>';
        case 'Firefox': return '<i class="fa fa-firefox-browser"></i>';
        case 'Safari': return '<i class="fa fa-safari"></i>';
        case 'Edge': return '<i class="fa fa-edge"></i>';
        case 'Opera': return '<i class="fa fa-opera"></i>';
        case 'IE': return '<i class="fa fa-internet-explorer"></i>';
        default: return '<i class="fa fa-question-circle"></i>';
    }
}



require_once 'NumberToWords.php';